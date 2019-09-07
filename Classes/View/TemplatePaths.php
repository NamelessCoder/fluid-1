<?php
namespace TYPO3\CMS\Fluid\View;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Object\ObjectManager;

/**
 * Class TemplatePaths
 *
 * Custom implementation for template paths resolving, one which differs from the base
 * implementation in that it is capable of resolving template paths based on TypoScript
 * configuration when given a package name, and is aware of the Frontend/Backend contexts of TYPO3.
 *
 * @internal This is for internal Fluid use only.
 */
class TemplatePaths extends \TYPO3Fluid\Fluid\View\TemplatePaths
{
    /**
     * @var string
     */
    protected $templateSource;

    /**
     * @var string
     */
    protected $templatePathAndFilename;

    /**
     * @param string $extensionKey
     * @return string|null
     */
    protected function getExtensionPrivateResourcesPath($extensionKey)
    {
        $extensionKey = trim($extensionKey);
        if ($extensionKey && ExtensionManagementUtility::isLoaded($extensionKey)) {
            return ExtensionManagementUtility::extPath($extensionKey) . 'Resources/Private/';
        }
        return null;
    }

    /**
     * @return ConfigurationManagerInterface
     */
    protected function getConfigurationManager()
    {
        $objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        $configurationManager = $objectManager->get(ConfigurationManagerInterface::class);
        return $configurationManager;
    }

    /**
     * @param string $extensionKey
     * @return array
     */
    protected function getContextSpecificViewConfiguration($extensionKey)
    {
        $systemPaths = [];
        $configuredPaths = [
            self::CONFIG_TEMPLATEROOTPATHS => $this->templateRootPaths,
            self::CONFIG_PARTIALROOTPATHS => $this->partialRootPaths,
            self::CONFIG_LAYOUTROOTPATHS => $this->layoutRootPaths,
        ];
        if (!empty($extensionKey)) {
            $typoScript = (array)$this->getConfigurationManager()->getConfiguration(ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT);
            $signature = str_replace('_', '', $extensionKey);
            if ($this->isBackendMode() && isset($typoScript['module.']['tx_' . $signature . '.']['view.'])) {
                $configuredPaths = (array)$typoScript['module.']['tx_' . $signature . '.']['view.'];
                $configuredPaths = GeneralUtility::removeDotsFromTS($configuredPaths);
            } elseif ($this->isFrontendMode() && isset($typoScript['plugin.']['tx_' . $signature . '.']['view.'])) {
                $configuredPaths = (array)$typoScript['plugin.']['tx_' . $signature . '.']['view.'];
                $configuredPaths = GeneralUtility::removeDotsFromTS($configuredPaths);
            }
            $resources = $this->getExtensionPrivateResourcesPath($extensionKey);
            $systemPaths = [
                self::CONFIG_TEMPLATEROOTPATHS => [$resources . 'Templates/'],
                self::CONFIG_PARTIALROOTPATHS => [$resources . 'Partials/'],
                self::CONFIG_LAYOUTROOTPATHS => [$resources . 'Layouts/']
            ];
        }

        // Create a set of paths consisting of defaults plus local plus globally defined paths, with local overriding global and default.
        $configuredPaths = array_merge_recursive(
            $systemPaths,
            static::getGlobalConfiguredPaths($extensionKey),
            $configuredPaths
        );

        foreach ($systemPaths as $name => $defaultPaths) {
            if (!empty($configuredPaths[$name])) {
                $systemPaths[$name] = array_merge($defaultPaths, ArrayUtility::sortArrayWithIntegerKeys((array)$configuredPaths[$name]));
            }
        }

        return array_map('array_filter', $systemPaths);
    }

    /**
     * Temporary public interface to load all globally configured paths,
     * reducing code duplication. Called from Extbase's ConfigurationManager
     * when determining View paths. Returns a combined set of paths where the
     * more specific paths overrule the lower-specificity ones. For example,
     * if paths are defined for a specific plugin then will be the top priority,
     * followed by paths for the extension and finally global paths with the
     * lowest possible priority.
     *
     * History / future:
     *
     * - Extbase view configuration is resolved by the ConfigurationManager
     *   and forcibly set through public setter methods directly on the
     *   view, which then delegates to TemplatePaths.
     * - This makes TemplatePaths NOT resolve this view configuration
     *   internally, instead using the defined paths.
     * - In the future this should be homogenised to have a single place
     *   where template path configurations are analysed - which should
     *   be in THIS class.
     * - That means the usage in Extbase's ConfigurationManager, as well
     *   as the processing in ActionController, can be deprecated and removed.
     * - When $this->getContextSpecificViewConfiguration is refactored to take
     *   over what ConfigurationManager does today, it will need a second
     *   argument to identify the plugin name context variable.
     *
     * See:
     *
     * - \TYPO3\CMS\Extbase\Mvc\Controller\ActionController::setViewConfiguration
     * - \TYPO3\CMS\Extbase\Mvc\Controller\ActionController::getViewProperty
     * - \TYPO3\CMS\Extbase\Configuration\AbstractConfigurationManager::getExtbaseConfiguration
     *
     * @param string|null $extensionName
     * @param string|null $pluginName
     * @return array
     * @internal
     */
    public static function getGlobalConfiguredPaths(?string $extensionName = null, ?string $pluginName = null): array
    {
        return array_merge_recursive(
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['fluid']['paths']['global'] ?? [],
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['fluid']['paths'][$extensionName] ?? [],
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['fluid']['paths'][$extensionName][$pluginName] ?? []
        );
    }

    /**
     * Fills the path arrays with defaults, by package name.
     * Reads those defaults from TypoScript if possible and
     * if not defined, uses fallback paths by convention.
     *
     * @param string $packageName
     */
    public function fillDefaultsByPackageName($packageName)
    {
        $this->fillFromConfigurationArray($this->getContextSpecificViewConfiguration($packageName));
    }

    /**
     * Overridden setter with enforced sorting behavior
     *
     * @param array $templateRootPaths
     */
    public function setTemplateRootPaths(array $templateRootPaths)
    {
        parent::setTemplateRootPaths(
            ArrayUtility::sortArrayWithIntegerKeys($templateRootPaths)
        );
    }

    /**
     * Overridden setter with enforced sorting behavior
     *
     * @param array $layoutRootPaths
     */
    public function setLayoutRootPaths(array $layoutRootPaths)
    {
        parent::setLayoutRootPaths(
            ArrayUtility::sortArrayWithIntegerKeys($layoutRootPaths)
        );
    }

    /**
     * Overridden setter with enforced sorting behavior
     *
     * @param array $partialRootPaths
     */
    public function setPartialRootPaths(array $partialRootPaths)
    {
        parent::setPartialRootPaths(
            ArrayUtility::sortArrayWithIntegerKeys($partialRootPaths)
        );
    }

    /**
     * Public API for currently protected method. Can be dropped when switching to
     * Fluid 1.1.0 or above.
     *
     * @param string $partialName
     * @return string
     */
    public function getPartialPathAndFilename($partialName)
    {
        return parent::getPartialPathAndFilename($partialName);
    }

    /**
     * Get absolute path to template file
     *
     * @return string Returns the absolute path to a Fluid template file
     */
    public function getTemplatePathAndFilename()
    {
        return $this->templatePathAndFilename;
    }

    /**
     * Guarantees that $reference is turned into a
     * correct, absolute path. The input can be a
     * relative path or a FILE: or EXT: reference
     * but cannot be a FAL resource identifier.
     *
     * @param mixed $reference
     * @return string
     */
    protected function ensureAbsolutePath($reference)
    {
        if (!is_array($reference)) {
            return PathUtility::isAbsolutePath($reference) ? $reference : GeneralUtility::getFileAbsFileName($reference);
        }
        foreach ($reference as &$subValue) {
            $subValue = $this->ensureAbsolutePath($subValue);
        }
        return $reference;
    }

    /**
     * @return bool
     */
    protected function isBackendMode()
    {
        return TYPO3_MODE === 'BE';
    }

    /**
     * @return bool
     */
    protected function isFrontendMode()
    {
        return TYPO3_MODE === 'FE';
    }
}
