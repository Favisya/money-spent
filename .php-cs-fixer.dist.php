<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/migrations'])
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12'                  => true,
        'declare_strict_types'    => true,
        'no_unused_imports'       => true,
        'ordered_imports'         => ['imports_order' => ['class', 'function', 'const'], 'sort_algorithm' => 'alpha'],
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'binary_operator_spaces'  => [
            'default'   => 'single_space',
            'operators' => ['=' => 'align_single_space_minimal', '=>' => 'align_single_space_minimal'],
        ],
        'no_extra_blank_lines'    => true,
    ])
    ->setFinder($finder)
;
