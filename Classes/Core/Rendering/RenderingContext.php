<?php
namespace TYPO3\CMS\Fluid\Core\Rendering;

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

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Fluid\Core\Cache\FluidTemplateCache;
use TYPO3\CMS\Fluid\Core\Parser\InterceptorInterface;
use TYPO3\CMS\Fluid\Core\Variables\CmsVariableProvider;
use TYPO3\CMS\Fluid\Core\ViewHelper\ViewHelperResolver;
use TYPO3\CMS\Fluid\View\TemplatePaths;
use TYPO3Fluid\Fluid\Core\Parser\Configuration;
use TYPO3Fluid\Fluid\Core\Parser\TemplateParser;
use TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperInvoker;
use TYPO3Fluid\Fluid\Core\Variables\StandardVariableProvider;
use TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperVariableContainer;
use TYPO3Fluid\Fluid\View\ViewInterface;

/**
 * Class RenderingContext
 */
class RenderingContext extends \TYPO3Fluid\Fluid\Core\Rendering\RenderingContext
{
    /**
     * Template Variable Container. Contains all variables available through object accessors in the template
     *
     * @var \TYPO3\CMS\Fluid\Core\ViewHelper\TemplateVariableContainer
     */
    protected $templateVariableContainer;

    /**
     * Object manager which is bubbled through. The ViewHelperNode cannot get an ObjectManager injected because
     * the whole syntax tree should be cacheable
     *
     * @var \TYPO3\CMS\Extbase\Object\ObjectManagerInterface
     * @deprecated since TYPO3 v8, will be removed in TYPO3 v9
     */
    protected $objectManager;

    /**
     * Controller context being passed to the ViewHelper
     *
     * @var \TYPO3\CMS\Extbase\Mvc\Controller\ControllerContext
     */
    protected $controllerContext;

    /**
     * Use legacy behavior? Can be overridden using setLegacyMode().
     *
     * @deprecated since TYPO3 v8, will be removed in TYPO3 v9
     * @var bool
     */
    protected $legacyMode = false;

    /**
     * @param \TYPO3\CMS\Extbase\Object\ObjectManagerInterface $objectManager
     * @deprecated since TYPO3 v8, will be removed in TYPO3 v9
     */
    public function injectObjectManager(\TYPO3\CMS\Extbase\Object\ObjectManagerInterface $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * @param \TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperVariableContainer $viewHelperVariableContainer
     */
    public function injectViewHelperVariableContainer(\TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperVariableContainer $viewHelperVariableContainer)
    {
        $this->viewHelperVariableContainer = $viewHelperVariableContainer;
    }

    /**
     * @param ViewInterface $view
     */
    public function __construct(ViewInterface $view = null)
    {
        $objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        $this->setExpressionNodeTypes($GLOBALS['TYPO3_CONF_VARS']['SYS']['fluid']['expressionNodeTypes']);
        $this->setTemplatePaths($objectManager->get(TemplatePaths::class));
    }

    /**
     * Set legacy compatibility mode on/off by boolean.
     * If set to FALSE, the ViewHelperResolver will only load a limited sub-set of ExpressionNodes,
     * making Fluid behave like the legacy version of the CMS core extension.
     *
     * @deprecated since TYPO3 v8, will be removed in TYPO3 v9
     * @param bool $legacyMode
     */
    public function setLegacyMode($legacyMode)
    {
        GeneralUtility::logDeprecatedFunction();
        $this->legacyMode = $legacyMode;
    }

    /**
     * Returns the object manager. Only the ViewHelperNode should do this.
     *
     * @deprecated since TYPO3 v8, will be removed in TYPO3 v9
     * @return \TYPO3\CMS\Extbase\Object\ObjectManagerInterface
     */
    public function getObjectManager()
    {
        return $this->objectManager;
    }

    /**
     * Get the template variable container (DEPRECATED; use getVariableProvider instead)
     *
     * @deprecated since TYPO3 CMS 8, will be removed in TYPO3 CMS 9 - use getVariableProvider instead
     * @see getVariableProvider
     * @return \TYPO3\CMS\Fluid\Core\ViewHelper\TemplateVariableContainer The Template Variable Container
     */
    public function getTemplateVariableContainer()
    {
        GeneralUtility::deprecationLog(
            'getTemplateVariableContainer is deprecated since TYPO3 CMS 8, will be removed in TYPO3 CMS 9' .
            ' - use getVariableProvider instead'
        );
        return $this->variableProvider;
    }

    /**
     * Get the controller context which will be passed to the ViewHelper
     *
     * @return \TYPO3\CMS\Extbase\Mvc\Controller\ControllerContext The controller context to set
     */
    public function getControllerContext()
    {
        return $this->controllerContext;
    }

    /**
     * @param string $action
     */
    public function setControllerAction($action)
    {
        $dotPosition = strpos($action, '.');
        if ($dotPosition !== false) {
            $action = substr($action, 0, $dotPosition);
        }
        $this->controllerContext->getRequest()->setControllerActionName(lcfirst($action));
    }

    /**
     * @param string $controllerName
     * @throws \TYPO3\CMS\Extbase\Mvc\Exception\InvalidControllerNameException
     */
    public function setControllerName($controllerName)
    {
        $this->controllerContext->getRequest()->setControllerName($controllerName);
    }

    /**
     * Set the controller context which will be passed to the ViewHelper
     *
     * @param \TYPO3\CMS\Extbase\Mvc\Controller\ControllerContext $controllerContext The controller context to set
     */
    public function setControllerContext(\TYPO3\CMS\Extbase\Mvc\Controller\ControllerContext $controllerContext)
    {
        $request = $controllerContext->getRequest();
        $this->controllerContext = $controllerContext;
        $this->setControllerAction($request->getControllerActionName());
        // Check if Request is using a sub-package key; in which case we translate this
        // for our RenderingContext as an emulated plain old sub-namespace controller.
        $controllerName = $request->getControllerName();
        if ($request->getControllerSubpackageKey() && !strpos($controllerName, '\\')) {
            $this->setControllerName($request->getControllerSubpackageKey() . '\\' . $controllerName);
        } else {
            $this->setControllerName($controllerName);
        }
    }

    /**
     * @return string
     */
    public function getControllerName()
    {
        return $this->controllerContext ? $this->controllerContext->getRequest()->getControllerName() : 'Default';
    }

    /**
     * @return string
     */
    public function getControllerAction()
    {
        return $this->controllerContext ? $this->controllerContext->getRequest()->getControllerActionName() : 'Default';
    }
}
