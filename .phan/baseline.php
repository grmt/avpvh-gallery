<?php
/**
 * This is an automatically generated baseline for Phan issues.
 * When Phan is invoked with --load-baseline=path/to/baseline.php,
 * The pre-existing issues listed in this file won't be emitted.
 *
 * This file can be updated by invoking Phan with --save-baseline=path/to/baseline.php
 * (can be combined with --load-baseline)
 */
return [
    // # Issue statistics:
    // PhanPluginPossiblyStaticPublicMethod : 25+ occurrences
    // PhanUnusedVariableCaughtException : 15+ occurrences
    // PhanPluginNonBoolInLogicalArith : 10+ occurrences
    // PhanPluginNonBoolBranch : 6 occurrences
    // PhanTypeInvalidDimOffset : 4 occurrences
    // PhanVariableDefinitionCouldBeConstant : 4 occurrences
    // PhanVariableDefinitionCouldBeConstantString : 4 occurrences
    // PhanNoopNew : 3 occurrences
    // PhanPossiblyNonClassMethodCall : 3 occurrences
    // PhanUnusedPublicFinalMethodParameter : 3 occurrences
    // PhanPluginPossiblyStaticPrivateMethod : 2 occurrences
    // PhanRedundantArrayValuesCall : 2 occurrences
    // PhanTypeMismatchArgumentInternalProbablyReal : 2 occurrences
    // PhanPluginDuplicateCatchStatementBody : 1 occurrence
    // PhanThrowTypeAbsent : 1 occurrence
    // PhanTypeArraySuspiciousNullable : 1 occurrence
    // PhanTypeInvalidLeftOperandOfAdd : 1 occurrence
    // PhanTypeInvalidLeftOperandOfNumericOp : 1 occurrence

    // Currently, file_suppressions and directory_suppressions are the only supported suppressions
    'file_suppressions' => [
        '.phan/stubs/class-avpvh-db.php' => ['PhanUnusedPublicFinalMethodParameter'],
        'src/php/admin/exif-inspector/class-browse-rest.php' => ['PhanPluginDuplicateCatchStatementBody', 'PhanPluginPossiblyStaticPublicMethod', 'PhanThrowTypeAbsent', 'PhanUnusedVariableCaughtException'],
        'src/php/admin/exif-inspector/class-camera-model-index-rest.php' => ['PhanPluginPossiblyStaticPublicMethod', 'PhanRedundantArrayValuesCall', 'PhanUnusedVariableCaughtException'],
        'src/php/admin/exif-inspector/class-corrections-rest.php' => ['PhanPluginNonBoolInLogicalArith', 'PhanPluginPossiblyStaticPublicMethod', 'PhanTypeArraySuspiciousNullable', 'PhanVariableDefinitionCouldBeConstant'],
        'src/php/admin/exif-inspector/class-exif-data-rest.php' => ['PhanPluginNonBoolBranch', 'PhanPluginNonBoolInLogicalArith', 'PhanPluginPossiblyStaticPublicMethod', 'PhanPossiblyNonClassMethodCall', 'PhanTypeMismatchArgumentInternalProbablyReal', 'PhanUnusedVariableCaughtException', 'PhanVariableDefinitionCouldBeConstant'],
        'src/php/admin/exif-inspector/class-folder-authors-rest.php' => ['PhanPluginPossiblyStaticPublicMethod'],
        'src/php/admin/exif-inspector/class-media-stream-rest.php' => ['PhanPluginPossiblyStaticPublicMethod', 'PhanPossiblyNonClassMethodCall', 'PhanUnusedVariableCaughtException'],
        'src/php/admin/settings-pages/class-advanced-settings.php' => ['PhanNoopNew', 'PhanVariableDefinitionCouldBeConstantString'],
        'src/php/admin/settings-pages/class-basic-settings.php' => ['PhanVariableDefinitionCouldBeConstantString'],
        'src/php/admin/settings-pages/class-exif-inspector.php' => ['PhanPluginPossiblyStaticPublicMethod'],
        'src/php/class-main.php' => ['PhanUnusedPublicFinalMethodParameter', 'PhanVariableDefinitionCouldBeConstant', 'PhanVariableDefinitionCouldBeConstantString'],
        'src/php/frontend/class-members-api.php' => ['PhanPluginPossiblyStaticPublicMethod'],
        'src/php/frontend/class-photo-tags.php' => ['PhanPluginNonBoolBranch', 'PhanPluginNonBoolInLogicalArith', 'PhanPluginPossiblyStaticPrivateMethod', 'PhanPluginPossiblyStaticPublicMethod'],
        'src/php/frontend/page/class-directories.php' => ['PhanTypeInvalidDimOffset', 'PhanTypeInvalidLeftOperandOfAdd', 'PhanTypeInvalidLeftOperandOfNumericOp'],
    ],
    // 'directory_suppressions' => ['src/directory_name' => ['PhanIssueName1', 'PhanIssueName2']] can be manually added if needed.
    // (directory_suppressions will currently be ignored by subsequent calls to --save-baseline, but may be preserved in future Phan releases)
];
