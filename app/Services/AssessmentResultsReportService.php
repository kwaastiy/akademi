<?php
declare(strict_types=1);

namespace Akademi\Services;

use RuntimeException;

final class AssessmentResultsReportService
{
    public static function attachments(array $result): array
    {
        $report = self::reportData($result);
        $title = self::safeName((string)($report['assessment']['title'] ?? 'assessment'));
        return [
            [
                'name' => $title . '-results-report.pdf',
                'mime' => 'application/pdf',
                'content' => self::pdf($report),
            ],
            [
                'name' => $title . '-results-report.docx',
                'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'content' => self::docx($report),
            ],
        ];
    }

    private static function reportData(array $result): array
    {
        $assessment = $result['assessment'] ?? [];
        $questions = $result['questions'] ?? [];
        $students = $result['students'] ?? [];
        $totalMarks = max(0, (int)($assessment['total_marks'] ?? 0));
        $hasWritten = false;
        foreach ($questions as $question) {
            if (($question['question_type'] ?? '') !== 'mcq') $hasWritten = true;
        }

        $learnerRows = [];
        $finalScores = [];
        $classes = [];
        $submittedCount = 0;
        $pendingCount = 0;
        $inProgressCount = 0;
        $notStartedCount = 0;
        foreach ($students as $student) {
            $status = (string)($student['status'] ?? 'not_started');
            $completed = in_array($status, ['submitted', 'expired'], true);
            $manualScore = $student['manual_score'] ?? null;
            $pending = $completed && $hasWritten && $manualScore === null;
            if ($completed) $submittedCount++;
            elseif ($status === 'in_progress') $inProgressCount++;
            else $notStartedCount++;
            if ($pending) $pendingCount++;
            $score = $completed && !$pending
                ? max(0, (int)($student['auto_score'] ?? 0) + (int)($manualScore ?? 0)
                    - (int)($student['integrity_marks_deducted'] ?? 0))
                : null;
            $className = (string)($student['class_name'] ?? 'Unassigned class');
            $classes[$className] ??= ['roster' => 0, 'completed' => 0, 'pending' => 0, 'scores' => []];
            $classes[$className]['roster']++;
            if ($completed) $classes[$className]['completed']++;
            if ($pending) $classes[$className]['pending']++;
            if ($score !== null) {
                $finalScores[] = $score;
                $classes[$className]['scores'][] = $score;
            }
            $learnerRows[] = [
                'roster_id' => (int)($student['roster_id'] ?? 0),
                'class' => $className,
                'name' => (string)($student['learner_name'] ?? ''),
                'status' => str_replace('_', ' ', $status),
                'objective' => $completed ? (int)($student['auto_score'] ?? 0) : null,
                'written' => $manualScore === null ? null : (int)$manualScore,
                'deduction' => (int)($student['integrity_marks_deducted'] ?? 0),
                'score' => $score,
                'percentage' => $score === null || $totalMarks < 1 ? null : round($score / $totalMarks * 100, 1),
                'pending' => $pending,
                'submitted' => (string)($student['submitted_at'] ?? ''),
                'question_reviews' => is_array($student['question_reviews'] ?? null) ? $student['question_reviews'] : [],
                'answers' => is_array($student['answers'] ?? null) ? $student['answers'] : [],
            ];
        }
        foreach ($classes as &$class) {
            $class['mean'] = $class['scores'] === [] ? null : array_sum($class['scores']) / count($class['scores']);
        }
        unset($class);
        $classRanks = [];
        foreach ($classes as $className => $class) {
            $ranked = array_values(array_filter(
                $learnerRows,
                static fn(array $learner): bool => $learner['class'] === $className && $learner['score'] !== null
            ));
            usort($ranked, static fn(array $left, array $right): int => $right['score'] <=> $left['score']);
            $previousScore = null;
            $rank = 0;
            foreach ($ranked as $index => $learner) {
                if ($learner['score'] !== $previousScore) $rank = $index + 1;
                $classRanks[$className][$learner['roster_id']] = $rank;
                $previousScore = $learner['score'];
            }
        }
        foreach ($learnerRows as &$learner) {
            $learner['rank'] = $learner['score'] === null
                ? 'Not ranked'
                : (string)($classRanks[$learner['class']][$learner['roster_id']] ?? '—');
        }
        unset($learner);
        usort($finalScores, static fn(int|float $left, int|float $right): int => $left <=> $right);
        $mean = $finalScores === [] ? null : array_sum($finalScores) / count($finalScores);
        $middle = intdiv(count($finalScores), 2);
        $median = $finalScores === [] ? null
            : (count($finalScores) % 2 ? $finalScores[$middle] : ($finalScores[$middle - 1] + $finalScores[$middle]) / 2);
        $standardDeviation = $mean === null ? null : sqrt(array_sum(array_map(
            static fn(int|float $score): float => ($score - $mean) ** 2,
            $finalScores
        )) / count($finalScores));

        $questionRows = [];
        foreach ($questions as $index => $question) {
            $type = (string)($question['question_type'] ?? 'other');
            $marks = max(0, (int)($question['marks'] ?? 0));
            $seen = 0;
            $earned = 0;
            foreach ($learnerRows as $learner) {
                if (!in_array($learner['status'], ['submitted', 'expired'], true)) continue;
                if ($type === 'mcq') {
                    $answer = $learner['answers'][(string)$index] ?? '';
                    if ($answer === '') continue;
                    $seen++;
                    if ((int)$answer === (int)($question['correct_option'] ?? -1)) $earned += $marks;
                } else {
                    $review = $learner['question_reviews'][(string)$index] ?? null;
                    if (!is_array($review) || !isset($review['score'])) continue;
                    $seen++;
                    $earned += max(0, (int)$review['score']);
                }
            }
            $questionRows[] = [
                'number' => $index + 1,
                'type' => str_replace('_', ' ', $type),
                'prompt' => self::plain((string)($question['prompt'] ?? '')),
                'marks' => $marks,
                'responses' => $seen,
                'average' => $seen ? $earned / $seen : null,
                'rate' => $seen && $marks > 0 ? $earned / ($seen * $marks) * 100 : null,
            ];
        }
        $responseRows = [];
        foreach ($learnerRows as $learner) {
            if (!in_array($learner['status'], ['submitted', 'expired'], true)) continue;
            foreach ($questions as $index => $question) {
                $type = (string)($question['question_type'] ?? 'other');
                $answer = (string)($learner['answers'][(string)$index] ?? '');
                $review = $learner['question_reviews'][(string)$index] ?? [];
                $markStatus = 'Awaiting marking';
                $feedback = (string)($review['comment'] ?? '');
                if ($type === 'mcq' && $answer !== '') {
                    $options = is_array($question['options'] ?? null) ? $question['options'] : [];
                    $optionIndex = (int)$answer;
                    $answer = isset($options[$optionIndex])
                        ? chr(65 + $optionIndex) . '. ' . (string)$options[$optionIndex]
                        : 'Saved response: ' . $answer;
                    $isCorrect = $optionIndex === (int)($question['correct_option'] ?? -1);
                    $markStatus = ($isCorrect ? (int)($question['marks'] ?? 0) : 0) . ' / ' . (int)($question['marks'] ?? 0);
                    $feedback = $isCorrect ? 'Correct' : 'Incorrect';
                } elseif ($type === 'mcq') {
                    $markStatus = '0 / ' . (int)($question['marks'] ?? 0);
                    $feedback = 'No response';
                } elseif (isset($review['score'])) {
                    $markStatus = (string)(int)$review['score'] . ' / ' . (int)($question['marks'] ?? 0);
                }
                $responseRows[] = [
                    $learner['roster_id'], $learner['class'], $learner['name'], 'Q' . ((int)$index + 1),
                    self::plain((string)($question['prompt'] ?? '')),
                    $answer !== '' ? self::plain($answer) : 'No response',
                    $markStatus,
                    self::plain($feedback),
                ];
            }
        }
        return [
            'assessment' => $assessment,
            'questions' => $questionRows,
            'responses' => $responseRows,
            'learners' => $learnerRows,
            'classes' => $classes,
            'total_marks' => $totalMarks,
            'submitted' => $submittedCount,
            'pending' => $pendingCount,
            'in_progress' => $inProgressCount,
            'not_started' => $notStartedCount,
            'finalized' => count($finalScores),
            'mean' => $mean,
            'median' => $median,
            'minimum' => $finalScores === [] ? null : $finalScores[0],
            'maximum' => $finalScores === [] ? null : $finalScores[count($finalScores) - 1],
            'standard_deviation' => $standardDeviation,
            'generated_at' => gmdate('Y-m-d H:i:s') . ' UTC',
        ];
    }

    private static function sections(array $report): array
    {
        $assessment = $report['assessment'];
        $fmt = static fn(mixed $value): string => $value === null ? '—' : number_format((float)$value, 1);
        $summary = [
            ['Field', 'Value'],
            ['Assessment', (string)($assessment['title'] ?? 'Assessment')],
            ['Subject', (string)($assessment['subject'] ?? 'Not specified')],
            ['Class(es)', (string)($assessment['class_name'] ?? 'Not specified')],
            ['Assessment type', str_replace('_', ' ', (string)($assessment['assessment_type'] ?? 'Not specified'))],
            ['Maximum marks', (string)$report['total_marks']],
            ['Availability status', (string)($assessment['status'] ?? 'Unknown')],
            ['Results published', (string)($assessment['result_published_at'] ?? 'Not published')],
            ['Report generated', $report['generated_at']],
        ];
        $overview = [
            'Rostered learners: ' . count($report['learners']),
            'Submitted or timed-out attempts: ' . $report['submitted'],
            'Finalized results included in statistics: ' . $report['finalized'],
            'Written reviews still pending: ' . $report['pending'],
            'Attempts in progress: ' . $report['in_progress'],
            'Learners not started: ' . $report['not_started'],
            'Mean finalized score: ' . ($report['mean'] === null ? 'not available' : $fmt($report['mean']) . ' / ' . $report['total_marks']),
            'Median finalized score: ' . ($report['median'] === null ? 'not available' : $fmt($report['median']) . ' / ' . $report['total_marks']),
            'Finalized score range: ' . ($report['minimum'] === null ? 'not available' : $fmt($report['minimum']) . '–' . $fmt($report['maximum'])),
            'Population standard deviation: ' . ($report['standard_deviation'] === null ? 'not available' : $fmt($report['standard_deviation']) . ' marks'),
        ];
        $classRows = [['Class', 'Roster', 'Completed', 'Pending written review', 'Mean finalized score']];
        foreach ($report['classes'] as $name => $class) {
            $classRows[] = [
                $name, (string)$class['roster'], (string)$class['completed'], (string)$class['pending'],
                $class['mean'] === null ? '—' : $fmt($class['mean']) . ' / ' . $report['total_marks'],
            ];
        }
        $questionRows = [['Question', 'Type', 'Max marks', 'Responses', 'Mean mark', 'Mean percent']];
        foreach ($report['questions'] as $question) {
            $questionRows[] = [
                'Q' . $question['number'] . ': ' . mb_substr($question['prompt'], 0, 100),
                $question['type'], (string)$question['marks'], (string)$question['responses'],
                $question['average'] === null ? 'Not marked' : $fmt($question['average']),
                $question['rate'] === null ? '—' : number_format($question['rate'], 1) . '%',
            ];
        }
        $distribution = [['Score band', 'Learners']];
        foreach ([[0, 20], [20, 40], [40, 60], [60, 80], [80, 101]] as [$min, $max]) {
            $count = count(array_filter(
                $report['learners'],
                static fn(array $learner): bool => $learner['percentage'] !== null
                    && $learner['percentage'] >= $min && $learner['percentage'] < $max
            ));
            $distribution[] = [$min . '–' . ($max === 101 ? 100 : $max - 1) . '%', (string)$count];
        }
        $analyzedQuestions = array_values(array_filter(
            $report['questions'],
            static fn(array $question): bool => $question['rate'] !== null
        ));
        usort($analyzedQuestions, static fn(array $left, array $right): int => $right['rate'] <=> $left['rate']);
        $interpretation = [
            ['Only submitted or timed-out attempts with completed marking are included in finalized-score statistics. Unmarked attempts are excluded.'],
            ['Question averages include answered MCQs or marked written responses only; written-question averages may change as marking is completed.'],
            ['Score bands describe the distribution only and are not pass/fail thresholds.'],
            ['Browser integrity events are signals for teacher review, not proof of misconduct.'],
        ];
        if ($analyzedQuestions !== []) {
            $best = $analyzedQuestions[0];
            $lowest = $analyzedQuestions[count($analyzedQuestions) - 1];
            $interpretation[] = ['Highest relative item performance: Q' . $best['number'] . ' at ' . number_format($best['rate'], 1) . '%.'];
            $interpretation[] = ['Lowest relative item performance: Q' . $lowest['number'] . ' at ' . number_format($lowest['rate'], 1) . '%. Review the item and learners’ misconceptions before drawing instructional conclusions.'];
        }
        $sections = [
            ['Assessment details', $summary],
            ['Executive summary', array_map(static fn(string $line): array => [$line], $overview)],
            ['Class comparison', $classRows],
            ['Score distribution', $distribution],
            ['Question-level analysis', $questionRows],
        ];
        foreach (array_keys($report['classes']) as $className) {
            $classLearners = array_values(array_filter(
                $report['learners'],
                static fn(array $learner): bool => $learner['class'] === $className
            ));
            usort($classLearners, static fn(array $left, array $right): int => strnatcasecmp($left['name'], $right['name']));
            $scoreRows = [['Learner', 'Attempt status', 'Objective', 'Written', 'Deduction', 'Final score', 'Percent', 'Class rank']];
            $responseRows = [['Item', 'Question', 'Learner response', 'Mark', 'Teacher feedback']];
            foreach ($classLearners as $learner) {
                $scoreRows[] = [
                    $learner['name'], $learner['status'],
                    $learner['objective'] === null ? '—' : (string)$learner['objective'],
                    $learner['written'] === null ? '—' : (string)$learner['written'],
                    (string)$learner['deduction'],
                    $learner['score'] === null ? 'Pending' : $learner['score'] . ' / ' . $report['total_marks'],
                    $learner['percentage'] === null ? '—' : number_format($learner['percentage'], 1) . '%',
                    $learner['rank'],
                ];
                if (!in_array($learner['status'], ['submitted', 'expired'], true)) continue;
                foreach ($report['responses'] as $response) {
                    if ((int)$response[0] !== $learner['roster_id']) continue;
                    $responseRows[] = [
                        $response[3], $response[4], $response[5], $response[6], $response[7],
                    ];
                }
            }
            if (count($responseRows) === 1) {
                $responseRows[] = ['—', 'No submitted responses', '—', '—', '—'];
            }
            $sections[] = ['Class results: ' . $className, $scoreRows];
            $sections[] = ['Question responses and teacher feedback: ' . $className, $responseRows];
        }
        $sections[] = ['Interpretation and limitations', $interpretation];
        return $sections;
    }

    private static function docx(array $report): string
    {
        $xml = static fn(string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $body = '';
        foreach (self::sections($report) as [$heading, $rows]) {
            $body .= '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>' . $xml($heading) . '</w:t></w:r></w:p>';
            $columnCount = max(1, ...array_map('count', $rows));
            if ($columnCount === 1) {
                foreach ($rows as $row) {
                    $body .= '<w:p><w:pPr><w:spacing w:after="80"/></w:pPr><w:r><w:t xml:space="preserve">' . $xml((string)($row[0] ?? '')) . '</w:t></w:r></w:p>';
                }
                continue;
            }
            $widths = match ($columnCount) {
                2 => [4200, 10200],
                5 => [850, 4300, 5000, 1400, 2850],
                6 => [3200, 1800, 1100, 1200, 3000, 4100],
                8 => [3600, 1700, 1250, 1250, 1450, 1900, 1100, 2150],
                default => array_fill(0, $columnCount, (int)(14400 / $columnCount)),
            };
            $borders = '<w:tblBorders><w:top w:val="single" w:sz="5" w:color="B8C8BC"/><w:left w:val="single" w:sz="5" w:color="B8C8BC"/><w:bottom w:val="single" w:sz="5" w:color="B8C8BC"/><w:right w:val="single" w:sz="5" w:color="B8C8BC"/><w:insideH w:val="single" w:sz="4" w:color="D5DED7"/><w:insideV w:val="single" w:sz="4" w:color="D5DED7"/></w:tblBorders>';
            $body .= '<w:tbl><w:tblPr><w:tblW w:w="14400" w:type="dxa"/><w:tblLayout w:type="fixed"/>' . $borders
                . '<w:tblCellMar><w:top w:w="65" w:type="dxa"/><w:left w:w="90" w:type="dxa"/><w:bottom w:w="65" w:type="dxa"/><w:right w:w="90" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>';
            foreach ($widths as $width) $body .= '<w:gridCol w:w="' . $width . '"/>';
            $body .= '</w:tblGrid>';
            foreach ($rows as $rowIndex => $row) {
                $header = $rowIndex === 0;
                $body .= '<w:tr>' . ($header ? '<w:trPr><w:tblHeader w:val="true"/></w:trPr>' : '');
                for ($column = 0; $column < $columnCount; $column++) {
                    $text = $xml((string)($row[$column] ?? ''));
                    $shade = $header
                        ? '<w:shd w:fill="245642"/>'
                        : (count($row) === 2 && $column === 0 ? '<w:shd w:fill="EAF1EB"/>' : '');
                    $runStyle = $header
                        ? '<w:rPr><w:b/><w:color w:val="FFFFFF"/><w:sz w:val="16"/></w:rPr>'
                        : '<w:rPr><w:sz w:val="16"/></w:rPr>';
                    $body .= '<w:tc><w:tcPr><w:tcW w:w="' . (int)($widths[$column] ?? 14400 / $columnCount) . '" w:type="dxa"/>' . $shade
                        . '<w:vAlign w:val="top"/></w:tcPr><w:p><w:pPr><w:spacing w:after="0"/></w:pPr><w:r>' . $runStyle
                        . '<w:t xml:space="preserve">' . $text . '</w:t></w:r></w:p></w:tc>';
                }
                $body .= '</w:tr>';
            }
            $body .= '</w:tbl><w:p/>';
        }
        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            . '<w:p><w:pPr><w:pStyle w:val="Title"/></w:pPr><w:r><w:t>' . $xml((string)($report['assessment']['title'] ?? 'Assessment')) . ' - Detailed Results and Analysis</w:t></w:r></w:p>'
            . $body
            . '<w:sectPr><w:pgSz w:w="15840" w:h="12240" w:orient="landscape"/><w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720"/></w:sectPr>'
            . '</w:body></w:document>';
        return self::zip([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
            'word/document.xml' => $document,
            'word/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/><w:sz w:val="20"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:rPr><w:b/><w:color w:val="18352B"/><w:sz w:val="34"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="180" w:after="80"/></w:pPr><w:rPr><w:b/><w:color w:val="2D6A4F"/><w:sz w:val="26"/></w:rPr></w:style></w:styles>',
        ]);
    }

    private static function pdf(array $report): string
    {
        $pageWidth = 842;
        $pageHeight = 595;
        $margin = 34;
        $pages = [[]];
        $y = 34;
        $font = static function (string $text): string {
            $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
            if ($converted === false) $converted = $text;
            $converted = preg_replace('/[^\x20-\x7e\x80-\xff]/', '?', $converted) ?? '';
            return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $converted);
        };
        $text = static function (string $value, int $x, int $top, int $size, bool $bold = false) use (&$pages, $pageHeight, $font): void {
            $baseline = $pageHeight - $top - $size;
            $pages[count($pages) - 1][] = 'BT /' . ($bold ? 'F2' : 'F1') . " {$size} Tf 0.12 0.20 0.15 rg 1 0 0 1 {$x} {$baseline} Tm (" . $font($value) . ") Tj ET\n";
        };
        $newPage = static function () use (&$pages, &$y): void { $pages[] = []; $y = 32; };
        $write = static function (string $value, int $size = 8, bool $bold = false, int $indent = 0) use (&$y, $pageHeight, $margin, $pageWidth, &$pages, $text, $newPage): void {
            $limit = max(20, (int)(($pageWidth - 2 * $margin - $indent) / ($size * 0.48)));
            $printable = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            $wrapped = explode("\n", wordwrap($printable === false ? $value : $printable, $limit, "\n", true));
            foreach ($wrapped as $line) {
                if ($y + $size + 4 > $pageHeight - 28) $newPage();
                $text($line, $margin + $indent, $y, $size, $bold);
                $y += $size + 4;
            }
            $y += 2;
        };
        $rect = static function (int $x, int $top, int $width, int $height, string $color, bool $fill) use (&$pages, $pageHeight): void {
            $operator = $fill ? 'rg' : 'RG';
            $suffix = $fill ? 'f' : 'S';
            $pages[count($pages) - 1][] = "{$color} {$operator} 0.5 w {$x} " . ($pageHeight - $top - $height) . " {$width} {$height} re {$suffix}\n";
        };
        $plain = static function (string $value): string {
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            return $converted === false ? $value : $converted;
        };
        $table = static function (array $rows) use (&$y, $pageHeight, $pageWidth, $margin, $newPage, $rect, $text, $plain): void {
            if ($rows === []) {
                $text('No learner responses are available for this class.', $margin, $y, 7);
                $y += 15;
                return;
            }
            $columnCount = max(1, ...array_map('count', $rows));
            if ($columnCount === 1) {
                foreach ($rows as $row) {
                    $limit = (int)(($pageWidth - 2 * $margin) / 4.2);
                    foreach (explode("\n", wordwrap($plain((string)($row[0] ?? '')), $limit, "\n", true)) as $line) {
                        if ($y + 13 > $pageHeight - 28) $newPage();
                        $text($line, $margin, $y, 8);
                        $y += 12;
                    }
                    $y += 2;
                }
                return;
            }
            $availableWidth = $pageWidth - 2 * $margin;
            $weights = match ($columnCount) {
                2 => [0.30, 0.70],
                5 => [0.07, 0.27, 0.31, 0.10, 0.25],
                6 => [0.25, 0.14, 0.09, 0.10, 0.18, 0.24],
                8 => [0.19, 0.12, 0.08, 0.08, 0.09, 0.15, 0.09, 0.20],
                default => array_fill(0, $columnCount, 1 / $columnCount),
            };
            $widths = [];
            foreach (array_keys($weights) as $index) $widths[] = (int)round($availableWidth * $weights[$index]);
            $widths[count($widths) - 1] += $availableWidth - array_sum($widths);
            $header = $rows[0];
            $drawHeader = static function () use (&$y, $header, $widths, $margin, $plain, $rect, $text, $newPage, $pageHeight): void {
                if ($y + 26 > $pageHeight - 28) $newPage();
                $x = $margin;
                foreach ($widths as $index => $width) {
                    $rect($x, $y, $width, 24, '0.86 0.91 0.87', true);
                    $rect($x, $y, $width, 24, '0.55 0.66 0.58', false);
                    $limit = max(4, (int)(($width - 8) / 3.2));
                    $lines = explode("\n", wordwrap($plain((string)($header[$index] ?? '')), $limit, "\n", true));
                    foreach (array_slice($lines, 0, 2) as $lineIndex => $line) {
                        $text($line, $x + 4, $y + 4 + $lineIndex * 8, 6, true);
                    }
                    $x += $width;
                }
                $y += 24;
            };
            $drawHeader();
            foreach (array_slice($rows, 1) as $row) {
                $cellLines = [];
                foreach ($widths as $index => $width) {
                    $limit = max(4, (int)(($width - 8) / 3.15));
                    $cellLines[] = explode("\n", wordwrap($plain((string)($row[$index] ?? '')), $limit, "\n", true));
                }
                $lineIndex = 0;
                $lineHeight = 8;
                $rowLineCount = max(array_map('count', $cellLines));
                while ($lineIndex < $rowLineCount) {
                    if ($y + $lineHeight + 8 > $pageHeight - 28) {
                        $newPage();
                        $drawHeader();
                    }
                    $room = max(1, (int)(($pageHeight - 28 - $y - 8) / $lineHeight));
                    $lineCount = min($room, $rowLineCount - $lineIndex);
                    $height = $lineCount * $lineHeight + 8;
                    $x = $margin;
                    foreach ($widths as $index => $width) {
                        $rect($x, $y, $width, $height, '1 1 1', true);
                        $rect($x, $y, $width, $height, '0.72 0.79 0.73', false);
                        for ($line = 0; $line < $lineCount; $line++) {
                            $value = $cellLines[$index][$lineIndex + $line] ?? '';
                            if ($value !== '') $text($value, $x + 4, $y + 4 + $line * $lineHeight, 6);
                        }
                        $x += $width;
                    }
                    $y += $height;
                    $lineIndex += $lineCount;
                }
            }
            $y += 7;
        };
        $title = (string)($report['assessment']['title'] ?? 'Assessment');
        $write($title . ' - Detailed Results and Analysis', 16, true);
        $write('Akademi | Confidential results report | Generated ' . $report['generated_at'], 8);
        foreach (self::sections($report) as [$heading, $rows]) {
            if ($y + 28 > $pageHeight - 28) $newPage();
            $y += 7;
            $pages[count($pages) - 1][] = '0.17 0.38 0.27 rg ' . $margin . ' ' . ($pageHeight - $y - 14) . ' 3 14 re f' . "\n";
            $text($heading, $margin + 9, $y, 10, true);
            $y += 19;
            $table($rows);
        }
        foreach ($pages as $index => &$commands) {
            $commands[] = "0.78 0.83 0.78 RG 0.6 w {$margin} 24 m " . ($pageWidth - $margin) . " 24 l S\n";
            $commands[] = 'BT /F1 7 Tf 0.38 0.43 0.39 rg 1 0 0 1 ' . $margin . ' 12 Tm (Akademi | Results report | Page ' . ($index + 1) . ' of ' . count($pages) . ") Tj ET\n";
        }
        unset($commands);
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];
        $pageRefs = [];
        foreach ($pages as $commands) {
            $stream = implode('', $commands);
            $pageNumber = count($objects) + 1;
            $contentNumber = $pageNumber + 1;
            $pageRefs[] = $pageNumber . ' 0 R';
            $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageWidth} {$pageHeight}] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents {$contentNumber} 0 R >>";
            $objects[] = '<< /Length ' . strlen($stream) . " >>\nstream\n{$stream}\nendstream";
        }
        $objects[1] = '<< /Type /Pages /Kids [' . implode(' ', $pageRefs) . '] /Count ' . count($pageRefs) . ' >>';
        $output = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($output);
            $output .= ($index + 1) . " 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($output);
        $output .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) $output .= sprintf("%010d 00000 n \n", $offset);
        return $output . 'trailer' . "\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    private static function zip(array $files): string
    {
        if (!function_exists('gzdeflate')) throw new RuntimeException('DOCX report compression is unavailable.', 503);
        $body = '';
        $central = '';
        $offset = 0;
        foreach ($files as $name => $content) {
            $name = (string)$name;
            $content = (string)$content;
            $compressed = gzdeflate($content);
            if ($compressed === false) throw new RuntimeException('The DOCX report could not be compressed.', 500);
            $crc = crc32($content);
            $size = strlen($compressed);
            $uncompressedSize = strlen($content);
            $header = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 8, 0, 0, $crc, $size, $uncompressedSize, strlen($name), 0);
            $body .= $header . $name . $compressed;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 8, 0, 0, $crc, $size, $uncompressedSize, strlen($name), 0, 0, 0, 0, 0, $offset)
                . $name;
            $offset += strlen($header) + strlen($name) + $size;
        }
        $count = count($files);
        return $body . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($body), 0);
    }

    private static function plain(string $value): string
    {
        $text = strip_tags($value);
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? $text);
    }

    private static function safeName(string $value): string
    {
        $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $name = preg_replace('/[^A-Za-z0-9_-]+/', '-', $name === false ? 'assessment' : $name) ?? 'assessment';
        return trim($name, '-') ?: 'assessment';
    }
}
