<?php

function debug($message) {
    //echo $message."\n";
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
Usually it comes to fixes like this one:
    from: ''{0}''
    to:    '{0}'
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
\t-d, --dry-run     Don't do any changes, just print the statistic to file 'corrections_found.txt'\n
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


function doCorrectionsInFile($translationsFileNames) {

    global $commonIssues;

	$corrections = [];

    $translations = [];
    foreach ($translationsFileNames as $translationsFileName) {
        $translationContent = file_get_contents($translationsFileName);
        $translations[$translationsFileName] = explode("\n", $translationContent);
    }

    foreach ($translations as $translationFileName => $translationLines) {

		$correctionsPerFile = (object)[
			"fileName" => $translationFileName,
			"correctionsCount" => 0,
			"lineCorrections" => []
		];

        foreach ($translationLines as $translationLine) {

			$correctionsPerLine = (object)[
				"issues" => [],
				"originalLine" => $translationLine
			];


			// loop for all issues we know
            foreach ($commonIssues as $correctVersion => $wrongVersions) {

                if (is_string($wrongVersions)) {
                    $wrongVersions = [$wrongVersions];
                }

                // iterate over each known version of this issue
                foreach ($wrongVersions as $wrongVersion) {

                    $regexp = false;
                    if ($wrongVersion[0] === "/" && $wrongVersion[strlen($wrongVersion) - 1] === "/") {
                        $regexp = true;
                    }

                    $matches = [];
                    if ($regexp === true) {
                        $rc = preg_match_all($wrongVersion, $translationLine, $matches);

                        if ($rc === 0) {
                            $rc = false;
                        }

                    } else {
                        $rc = strpos($translationLine, $wrongVersion);
                    }

                    // now, check if we found issue
                    if ($rc !== false) {

                        if ($regexp === true) {

                            debug("Found issue for: " . $correctVersion);
                            debug("Pattern for issue is: " . $wrongVersion);
                            debug("Translation file name is: " . $translationFileName);
                            debug("Line is: " . $translationLine);

                            for ($i = 0; $i < count($matches[0]); $i++) {

                                $replaceTo = $correctVersion;

                                for ($k = 0; ; $k++) {

                                    $patternForNumber = "\\" . ($k + 1);

                                    if (strpos($replaceTo, $patternForNumber) === false) {
                                        break;
                                    }

                                    $replaceTo = str_replace($patternForNumber, $matches[$k + 1][$i], $replaceTo);
                                }

                                $translationLine = str_replace($matches[0][$i], $replaceTo, $translationLine);
                            }

                            debug("Corrected line is: ".$translationLine);

                        } else {
                            debug("Found issue for " . $correctVersion);
                            debug("Translation file name is: " . $translationFileName);
                            debug("Line is: " . $translationLine);

                            $translationLine = str_replace($wrongVersion, $correctVersion, $translationLine);

                            debug("Corrected line is: ".$translationLine);
                        }

                        // empty line
                        debug("");

						$correctionsPerLine->issues[] = $wrongVersion;
                    }
                }
            }

			$correctionsPerLine->fixedLine = $translationLine;

			$correctionsMade = count($correctionsPerLine->issues);
			if ($correctionsMade > 0) {
				$correctionsPerFile->lineCorrections[] = $correctionsPerLine;
				$correctionsPerFile->correctionsCount += $correctionsMade;
			}
        }

        // if we do have any corrections, let's count them
        if ($correctionsPerFile->correctionsCount > 0) {
			$corrections[] = $correctionsPerFile;
		}
    }

    return $corrections;
}
