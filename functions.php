<?php

function debug($message) {
	if (VERBOSE_MODE) {
		echo $message . "\n";
	}
}

function pa($var, $exit = false) {
    if ($var === null) {
        $value = "NULL";
    } else if ($var === false) {
        $value = "FALSE";
    } else if ($var === true) {
        $value = "TRUE";
    } else {
        $value = print_r($var, 1);
    }

    echo $value . "\n";
    if ($exit) {
        exit;
    }
}

function help() {

    $description = "Script seeks for issues in translations for JRS made by internationalization team and fixes them.
Usually it comes to fix issues like this one:
    issue:   ''{0}''
    correct:  '{0}'
So it finds all '*.properties' files and tries to find issues in them.
Issues are described in file named 'translation_issues.json'. Format of this file is JSON and 
a 'key' means correct version while the 'value' of the 'key' describes errors made by translators.
To make things complicated you can specify regular expressions in 'values'.
Value of the 'key' can be a string or an array of strings. Other cases will lead to errors.
To specify in string regular expression start it and end it by '/' symbol. Example: \"/([^=])'' /\"
You can also use replace patterns in 'key', like this one: \"\\1' \"
Script prints statistic into file 'corrections_found.txt'.";

    $description = explode("\n", $description);

    foreach ($description as $lineNumber => $line) {
        $description[$lineNumber] = "\t".$line;
    }

    $description = implode("\n", $description);

    echo "Usage: ".$_SERVER["argv"][0]." [options] {translations folder}\n
\ttranslations folder:   The folder which has '*.properties' files with translations\n
Options are:
\t-v, --verbose     Verbose mode
\t-r, --replace     Do replaces in translation files\n
Example: ".$_SERVER["argv"][0]." -v ./tests\n
Description:
".$description."\n";
}

function findTranslationGroupInFiles (&$files) {

    // detecting "origin file name" can be done by knowledge that translation file name
    // made of origin file name plus language prefix
    // Example:
    // if origin file name is "ABC.properties", then translation would be
    // "ABC_aa.properties"
    // "ABC_bb.properties"
    // "ABC_cc_dd.properties"
    // "ABC_ee_ff.properties"
    // etc.
    // OR
    // "ABC_[^.]+.properties"

    $fileExtension = ".properties";

    $originFileName = "";
    $foundTranslations = false;
    $translationKeys = [];
    $translationFileNames = [];

    foreach ($files as $fileNameIndex => $fileName) {

        $beforeDot = substr($fileName, 0, strrpos($fileName, $fileExtension));
        $patternToFindTranslations = "/".$beforeDot."_[^.]+".$fileExtension."/";

        foreach ($files as $otherFileNameKey => $otherFileName) {
            // skip myself
            if ($fileNameIndex === $otherFileNameKey) {
                continue;
            }

            $matches = [];
            $rc = preg_match($patternToFindTranslations, $otherFileName, $matches);
            if ($rc === false) {
                die("Something bad going on, call the developer\n");
            }
            if ($rc === 0) {
                // did not match
                continue;
            }
            if (count($matches) === 1) {
                $foundTranslations = $foundTranslations || true;
                $translationKeys[] = $otherFileNameKey;
                $translationFileNames[] = $otherFileName;
            }
        }

        if ($foundTranslations === true) {
            $originFileName = $fileName;
            break;
        }
    }

    if ($foundTranslations === false) {
        return [false, null, null, null];
    }

    // Ok, this part of code means we found something.
    // We need to remove from $files the indexes of translations

    foreach ($translationKeys as $key) {
        unset($files[$key]);
    }
    ksort($files);

    //pa($originFileName);
    //pa($translationFileNames);

    return [true, $originFileName, $translationFileNames];
}

function getTranslationFilesInFolder($folder) {
    $files = scandir($folder);
    unset($files[0]); // remove "."
    unset($files[1]); // remove ".."
    ksort($files);

    // Some terms:
    //  "Translation group" is a group of files which have common name, like:
    //  security.properties
    //  security_de.properties
    //  security_es.properties
    //  security_fr.properties
    //  security_it.properties
    //  security_ja.properties
    //  security_pt_BR.properties
    //  security_zh.properties
    //  security_zh_CN.properties
    //
    // As you can see, there is an "origin file" ('security.properties')
    // and the translations ('security_de.properties', etc).
    //
    // What we gonna do is to take some file name and trying to understand if this is "origin file".
    // By finding "origin file" we later can find all translations of this file as well, abd thus
    // we'll collect all files into groups

    $translationGroups = [];

    while (true) {

        // During each iteration of this loop the size of the $files array
        // decreases because once the function findTranslationGroupInFiles()
        // finds the translation group if removed files from this array
        // So calling again and again this function will retrieve all translation groups

        list($rc, $originFileName, $translations) = findTranslationGroupInFiles($files);
        if ($rc === false) {
            // it means we didn't find any translation group
            break;
        }

        $translationGroups[$originFileName] = $translations;
    }

    return $translationGroups;
}


// searching '$translationLine' in '$originalContent'
// pass '$originalContent' by reference to make script runs faster
function getOriginalLine ($translationFileName, &$originalContent, $translationLine) {
	// break translation line by key and value
	// we don't use 'explode' because there can be many '=' characters

	$equalCharPosition = strpos($translationLine, "=");
	if ($equalCharPosition === false) {
		// what ?!
		echo "Translation '".$translationFileName."' has issue on line:\n\t'".$translationLine."'\n: it doesn't have character '='\n";
		exit;
	}
	$key = substr($translationLine, 0, $equalCharPosition);

	$keyPositionInOriginal = strpos($originalContent, $key);

	$value = "";
	$pos = $keyPositionInOriginal + strlen($key) + 1;
	// win endings: 0D 0A
	// unix ending: 0A
	// so it's safe to collect all chars up to 0A (\n)
	while ($originalContent[$pos] !== "\n") {
		$value = $value . $originalContent[$pos];
		$pos++;
	}

	$originLine = $key."=".$value;
	
	return $originLine;
}


function doCorrectionsInFile($originFile, $translationsFileNames) {

    global $commonIssues;

	// variable which we are going to return with some statistic
	$corrections = [];

	$originalContent = file_get_contents($originFile);

    $translations = [];
    foreach ($translationsFileNames as $translationsFileName) {
        $translationContent = file_get_contents($translationsFileName);
        $translations[$translationsFileName] = explode("\n", $translationContent);
    }

    foreach ($translations as $translationFileName => &$translationLines) {

		$correctionsPerFile = (object)[
			"fileName" => $translationFileName,
			"correctionsCount" => 0,
			"lineCorrections" => []
		];

        foreach ($translationLines as &$translationLine) {

			$correctionsPerLine = (object)[
				"issues" => [],
				"beforeCorrection" => $translationLine
			];

			// original line, we are blanking this variable to indicate that original line hasn't beed loaded
			$originLine = "";

			// loop for all issues we know
            foreach ($commonIssues as $correctVersion => $wrongVersions) {

                if (is_string($wrongVersions)) {
                    $wrongVersions = [$wrongVersions];
                }

                // iterate over each known version of this issue
                foreach ($wrongVersions as $wrongVersion) {

					$originalHasIssue = false;
					$translationHasIssue = false;

					$regexp = false;
					
					$translationMatches = [];
					// check translation against the issue: does it have it or not
					if ($wrongVersion[0] === "/" && $wrongVersion[strlen($wrongVersion) - 1] === "/") {
                        $regexp = true;
                        $rc = preg_match_all($wrongVersion, $translationLine, $translationMatches);
                        if ($rc === 0) {
                            $rc = false;
                        }
                    } else {
                        $rc = strpos($translationLine, $wrongVersion);
                    }
                    // 'rc' is the 'return code' after functions.
					// convert it to boolean variable (yes, in old-fashion way for dummies)
					if ($rc !== false) {
						$translationHasIssue = true;
					}


					// if translation has issue then we'll check original line against the same issue
					if ($translationHasIssue === true) {

						if ($originLine === "") {
							$originLine = getOriginalLine($translationFileName, $originalContent, $translationLine);
						}

						// checking original line if it has issue
						if ($regexp === true) {
							$_ignore = [];
							$rc = preg_match_all($wrongVersion, $originLine, $_ignore);
							if ($rc === 0) {
								$rc = false;
							}
						} else {
							$rc = strpos($originLine, $wrongVersion);
						}

						// 'rc' is the 'return code' after functions.
						// convert it to boolean variable (yes, in old-fashion way for dummies)
						if ($rc !== false) {
							$originalHasIssue = true;
						}
					}

                    // now, if original doesn't have issue and translation has it
					// it means translation team really did mistake. Correct it !
                    if ($originalHasIssue === false && $translationHasIssue === true) {

						debug("Found issue for: " . $correctVersion);
						debug("Translation file name is: " . $translationFileName);
						debug("Origin Line is: " . $originLine);
						debug("Line is: " . $translationLine);

                        if ($regexp === true) {
							debug("Pattern for issue is: " . $wrongVersion);
                            for ($i = 0; $i < count($translationMatches[0]); $i++) {

                                $replaceTo = $correctVersion;

                                for ($k = 0; ; $k++) {

                                    $patternForNumber = "\\" . ($k + 1);

                                    if (strpos($replaceTo, $patternForNumber) === false) {
                                        break;
                                    }

                                    $replaceTo = str_replace($patternForNumber, $translationMatches[$k + 1][$i], $replaceTo);
                                }

                                $translationLine = str_replace($translationMatches[0][$i], $replaceTo, $translationLine);
                            }
                        } else {
                            $translationLine = str_replace($wrongVersion, $correctVersion, $translationLine);
                        }

						debug("Corrected line is: ".$translationLine);

                        // empty line
                        debug("");

						$correctionsPerLine->issues[] = $wrongVersion;
                    }
                }
            }

			$correctionsPerLine->afterCorrection = $translationLine;
			$correctionsPerLine->originLine = $originLine;
			$originLine = "";

			// record '$correctionsPerLine' object only if it has corrections
			$correctionsMade = count($correctionsPerLine->issues);
			if ($correctionsMade > 0) {
				$correctionsPerFile->lineCorrections[] = $correctionsPerLine;
				$correctionsPerFile->correctionsCount += $correctionsMade;
			}
        }

		if (REPLACE_MODE) {
			$content = implode("\n", $translationLines);
			file_put_contents($translationFileName, $content);
		}

		// if we do have any corrections, let's count them
        if ($correctionsPerFile->correctionsCount > 0) {
			$corrections[] = $correctionsPerFile;
		}
    }

    return $corrections;
}
