<?php
declare(strict_types=1);

/**
 * The rules an entry obeys, in one place.
 *
 * Two things write entries: the web form in controllers/items.php and the API in
 * controllers/api.php. Each normalised its own input, so the same question had
 * two answers and nobody could see both at once - `condition` was a validated
 * enum on one side and a free text box on the other, and the rule that clearing
 * "there is a box" also clears the box grade existed twice, in different words,
 * with no reason to think they agreed.
 *
 * What is shared here is the *rule*: which values are allowed, and what follows
 * from a given answer. What is deliberately not shared is the *policy* on a bad
 * value - the form coerces to "unknown" because a person mid-typing should not
 * lose a page, and the API answers 422 because a client that sent nonsense wants
 * to know. Each function below returns null for "not a valid value" and leaves
 * the caller to decide what that means.
 */

/**
 * A grade for the thing itself, or null if that is not one.
 */
function rule_condition_grade(mixed $value): ?string
{
    $value = is_scalar($value) ? (string) $value : '';
    return in_array($value, condition_options(), true) ? $value : null;
}

/**
 * A grade for a piece - the box, the manual, the media.
 *
 * A different list from the whole: something can be `missing` while the entry
 * it belongs to is not.
 */
function rule_component_grade(mixed $value): ?string
{
    $value = is_scalar($value) ? (string) $value : '';
    return in_array($value, component_condition_options(), true) ? $value : null;
}

function rule_completeness(mixed $value): ?string
{
    $value = is_scalar($value) ? (string) $value : '';
    return in_array($value, completeness_options(), true) ? $value : null;
}

function rule_status(mixed $value): ?string
{
    $value = is_scalar($value) ? (string) $value : '';
    return in_array($value, status_options(), true) ? $value : null;
}

/**
 * Out of ten, or null. Zero is not a rating - it is "nothing said" - and the
 * column is nullable for exactly that.
 */
function rule_rating(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    $n = (int) $value;
    return ($n >= 1 && $n <= 10) ? $n : null;
}

/**
 * Whether there is a box, and what the box grade becomes.
 *
 * The rule nobody should have to re-derive: grading a box that is not there is
 * meaningless, so saying there is no box clears the grade with it. Without this,
 * unticking the box left "Mint" behind on a box nobody has.
 *
 * `$declared` is null when the caller has no box control at all - the software
 * form posts a box grade and no checkbox - and then the answer is inferred from
 * the grade, because somebody who graded a box has told you there is one.
 *
 * Returns ['has_box' => 0|1, 'condition_box' => string].
 */
function rule_box_state(?bool $declared, mixed $boxGrade): array
{
    $grade = rule_component_grade($boxGrade) ?? 'unknown';

    if ($declared === null) {
        $hasBox = in_array($grade, ['unknown', 'missing'], true) ? 0 : 1;
        return ['has_box' => $hasBox, 'condition_box' => $grade];
    }

    if ($declared === false) {
        return ['has_box' => 0, 'condition_box' => 'unknown'];
    }

    return ['has_box' => 1, 'condition_box' => $grade];
}
