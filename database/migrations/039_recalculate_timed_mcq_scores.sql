UPDATE assessment_attempts t
JOIN online_assessments a ON a.id = t.assessment_id
SET t.auto_score = (
    SELECT COALESCE(SUM(
        CASE
            WHEN questions.question_type = 'mcq'
                AND JSON_UNQUOTE(JSON_EXTRACT(
                    t.answers_json,
                    CONCAT('$."', questions.question_index - 1, '"')
                )) REGEXP '^[0-3]$'
                AND CAST(JSON_UNQUOTE(JSON_EXTRACT(
                    t.answers_json,
                    CONCAT('$."', questions.question_index - 1, '"')
                )) AS UNSIGNED) = questions.correct_option
            THEN questions.marks
            ELSE 0
        END
    ), 0)
    FROM JSON_TABLE(
        a.questions_json,
        '$[*]' COLUMNS (
            question_index FOR ORDINALITY,
            question_type VARCHAR(20) PATH '$.question_type',
            correct_option INT PATH '$.correct_option',
            marks INT PATH '$.marks'
        )
    ) AS questions
)
WHERE t.status = 'submitted' AND t.answers_json IS NOT NULL;
