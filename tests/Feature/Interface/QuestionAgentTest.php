<?php

use App\Interface\QuestionAgent;

it('découpe le format QUESTION / CONTEXTE / détail', function () {
    $q = QuestionAgent::depuis("QUESTION : On garde les 22 skills retenues ?\nCONTEXTE : Sur 36 propositions, 22 passent les audits. Le budget reste sous la limite.\n\n- Dev 6\n- marketing 5");

    expect($q)->toBe([
        'question' => 'On garde les 22 skills retenues ?',
        'contexte' => 'Sur 36 propositions, 22 passent les audits. Le budget reste sous la limite.',
        'detail' => "- Dev 6\n- marketing 5",
    ]);
});

it('accepte le format sans détail ni contexte, et en gras', function () {
    expect(QuestionAgent::depuis('**QUESTION :** Je publie ?'))->toBe(['question' => 'Je publie ?', 'contexte' => null, 'detail' => null]);
});

it('résume un message libre en deux phrases et replie le reste', function () {
    $corps = "POINT D'ARRÊT 2. Décision appliquée. Shortlist : 22 candidates sur 36. Budget 232 M.";
    $q = QuestionAgent::depuis($corps);

    expect($q['question'])->toBeNull()
        ->and($q['contexte'])->toBe("POINT D'ARRÊT 2. Décision appliquée.")
        ->and($q['detail'])->toBe($corps)
        ->and(QuestionAgent::depuis('Il manque la clé API.'))->toBe(['question' => null, 'contexte' => 'Il manque la clé API.', 'detail' => null]);
});
