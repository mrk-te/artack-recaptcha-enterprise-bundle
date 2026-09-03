<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;
use PhpCsFixerCustomFixers\Fixer\CommentSurroundedBySpacesFixer;
use PhpCsFixerCustomFixers\Fixer\ConstructorEmptyBracesFixer;
use PhpCsFixerCustomFixers\Fixer\EmptyFunctionBodyFixer;
use PhpCsFixerCustomFixers\Fixer\MultilineCommentOpeningClosingAloneFixer;
use PhpCsFixerCustomFixers\Fixer\NoDoctrineMigrationsGeneratedCommentFixer;
use PhpCsFixerCustomFixers\Fixer\NoDuplicatedArrayKeyFixer;
use PhpCsFixerCustomFixers\Fixer\NoDuplicatedImportsFixer;
use PhpCsFixerCustomFixers\Fixer\NoTrailingCommaInSinglelineFixer;
use PhpCsFixerCustomFixers\Fixer\NoUselessCommentFixer;
use PhpCsFixerCustomFixers\Fixer\NoUselessDirnameCallFixer;
use PhpCsFixerCustomFixers\Fixer\NoUselessDoctrineRepositoryCommentFixer;
use PhpCsFixerCustomFixers\Fixer\NoUselessStrlenFixer;
use PhpCsFixerCustomFixers\Fixer\PhpdocTypesTrimFixer;
use PhpCsFixerCustomFixers\Fixers;

/*
 * This document has been generated with
 * https://mlocati.github.io/php-cs-fixer-configurator/#version:2.16.4|configurator
 * you can change this configuration by importing this file.
 */
$config = new Config();

return $config
    ->registerCustomFixers(new Fixers())
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PHP8x1Migration' => true,
        '@PHP8x0Migration:risky' => true,
        '@PhpCsFixer' => true,
        '@PhpCsFixer:risky' => true,
        'attribute_empty_parentheses' => true,
        'class_keyword' => true,
        'date_time_create_from_format_call' => true,
        'date_time_immutable' => true,
        'final_public_method_for_abstract_class' => true,
        'global_namespace_import' => ['import_classes' => true, 'import_constants' => true, 'import_functions' => true],
        'mb_str_functions' => true,
        'multiline_string_to_heredoc' => true,
        'ordered_interfaces' => true,
        'phpdoc_array_type' => true,
        'phpdoc_line_span' => true,
        'phpdoc_param_order' => true,
        'phpdoc_separation' => ['skip_unlisted_annotations' => true],
        'phpdoc_types' => false,
        'regular_callable_call' => true,
        'return_to_yield_from' => true,
        'simplified_if_return' => true,
        'simplified_null_return' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arguments', 'arrays', 'match', 'parameters']],
        'multiline_promoted_properties' => ['minimum_number_of_parameters' => 2],
        CommentSurroundedBySpacesFixer::name() => true,
        ConstructorEmptyBracesFixer::name() => true,
        MultilineCommentOpeningClosingAloneFixer::name() => true,
        NoDoctrineMigrationsGeneratedCommentFixer::name() => true,
        NoUselessDoctrineRepositoryCommentFixer::name() => true,
        NoUselessCommentFixer::name() => true,
        NoUselessDirnameCallFixer::name() => true,
        EmptyFunctionBodyFixer::name() => true,
        NoDuplicatedArrayKeyFixer::name() => true,
        NoDuplicatedImportsFixer::name() => true,
        NoTrailingCommaInSinglelineFixer::name() => true,
        NoUselessStrlenFixer::name() => true,
        PhpdocTypesTrimFixer::name() => true,
    ])
    ->setFinder(
        Finder::create()
            ->in([__DIR__.'/src', __DIR__.'/tests']),
    )
;
