<?php
defined('TYPO3_MODE') or die();

unset(
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['fluid_template'],
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['fluid']['preProcessors']
);

$GLOBALS['TYPO3_CONF_VARS']['SYS']['fluid']['expressionNodeTypes'] = [
    \TYPO3Fluid\Fluid\ViewHelpers\Expression\CastViewHelper::class,
    \TYPO3Fluid\Fluid\ViewHelpers\Expression\MathViewHelper::class,
    \TYPO3Fluid\Fluid\ViewHelpers\IfViewHelper::class
];