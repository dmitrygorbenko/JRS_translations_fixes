#!/usr/bin/env php
<?php

require_once (__DIR__."/functions.php");

$commonIssuesFileName = "translation_issues.json";
if (!is_file($commonIssuesFileName)) {
    echo "Missed file '".$commonIssuesFileName."'. Please, update this code from repository\n";
    exit();
}
$commonIssues = json_decode(file_get_contents($commonIssuesFileName));
if ($commonIssues === null) {
    echo "Failed to parse JSON file '".$commonIssuesFileName."'. Please, update this code from repository\n";
    exit();
}
$commentsKey = "#";
unset($commonIssues->$commentsKey);


$args = $_SERVER["argv"];

if (count($args) < 2) {
    // not enough arguments
    help();
    exit;
}

$translationFolder  = $args[count($args) - 1];
if (!is_dir($translationFolder)) {
    help();
    exit;
}

$translationGroups = getTranslationFilesInFolder($translationFolder);

$statistic = (object)[
	"correctionsMade" => 0,
	"corrections" => []
];

foreach ($translationGroups as $originFile => $translations) {

    $originFile = $translationFolder."/".$originFile;
    foreach($translations as &$translation) {
        $translation = $translationFolder."/".$translation;
    }

	$correctionsPerFile = doCorrectionsInFile($translations);
	if (count($correctionsPerFile) > 0) {
		$statistic->corrections = array_merge($statistic->corrections, $correctionsPerFile);
	}
}

// calculate how many corrections in total we made
foreach ($statistic->corrections as $correctionsPerFile) {
	$statistic->correctionsMade += $correctionsPerFile->correctionsCount;
}

// print some statistic
if ($statistic->correctionsMade) {
	echo "Corrections made: " . $statistic->correctionsMade . "\n";
	foreach ($statistic->corrections as $correctionsPerFile) {
		if ($correctionsPerFile->correctionsCount > 0) {
			echo $correctionsPerFile->fileName.": ".$correctionsPerFile->correctionsCount." fix(es)\n";
		}
	}
} else {
	echo "Everything is clear, no corrections\n";
	exit;
}

// build more complicated report
$report = [];
$i = 0;
foreach ($statistic->corrections as $correctionsPerFile) {
    $fileRecord = [];
    $fileRecord[] = "Filename: ".$correctionsPerFile->fileName;
	$fileRecord[] = "Fixes made: ".$correctionsPerFile->correctionsCount;
	$fileRecord[] = "Corrected Lines: ".count($correctionsPerFile->lineCorrections);

	$fileCorrections = [];
	foreach ($correctionsPerFile->lineCorrections as $correctionsPerLine) {
		$lineRecord = [];

		$lineRecord[] = "Origin line: ".$correctionsPerLine->originalLine;
		$lineRecord[] = "Fixed line:  ".$correctionsPerLine->fixedLine;
		$lineRecord[] = "Issues found:  ".implode(", ", $correctionsPerLine->issues);
		$lineRecord = implode("\n\t", $lineRecord);

		$fileCorrections[] = $lineRecord;
	}
	$fileCorrections = implode("\n\n\t", $fileCorrections);
	$fileRecord[] = "Corrections:\n\t".$fileCorrections;

    $report[] = implode("\n", $fileRecord);
    $i++;
}
$report = implode("\n\n\n", $report);
file_put_contents("corrections_found.txt", $report);
