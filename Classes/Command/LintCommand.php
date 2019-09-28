<?php
declare(strict_types = 1);

namespace TYPO3\CMS\Fluid\Command;

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

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Fluid\Core\Rendering\RenderingContext;
use TYPO3Fluid\Fluid\Core\Exception;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;

/**
 * Lint Fluid templates
 *
 * Runs syntax checks on Fluid templates, using the
 * context provided by TYPO3 CMS.
 */
class LintCommand extends Command
{
    /**
     * Defines the allowed options for this command
     */
    protected function configure()
    {
        $this->setDescription('Lints (syntax-checks) Fluid templates with TYPO3 CMS context');
        $this->addOption('extension', 'e', InputOption::VALUE_OPTIONAL, 'Extension key which should be linted (all .html files in extension)');
        $this->addOption('file', 'f', InputOption::VALUE_OPTIONAL, 'File which should be linted');
        $this->addOption('path', 'p', InputOption::VALUE_OPTIONAL, 'Path which should be linted (all .html files in path)');
        $this->addOption(
            'fail',
            null,
            InputOption::VALUE_OPTIONAL,
            'If true, fails on the first encountered linting error. If true, continues linting all files and exits ' .
            'with error at the end if errors are encountered.',
            false
        );
    }

    /**
     * Lint Fluid templates
     *
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $extensionToLint = $input->getOption('extension');
        $fileToLint = $input->getOption('file');
        $pathToLint = $input->getOption('path');
        $fail = (bool) $input->getOption('fail');

        $errorsEncountered = 0;
        if ($fileToLint) {
            $potentialError = $this->lintFile($fileToLint, $extensionToLint, $io);
            if ($potentialError) {
                ++$errorsEncountered;
                $this->reportError($potentialError, $io);
            }
        } elseif ($pathToLint) {
            $io->write('Path: ' . $pathToLint, true);
            $errors = $this->lintPath($pathToLint, $extensionToLint, $fail, $io);
            $errorsEncountered += $errors;
        } else {
            if (!$extensionToLint) {
                $extensions = ExtensionManagementUtility::getLoadedExtensionListArray();
            } else {
                $extensions = [$extensionToLint];
            }
            foreach ($extensions as $extensionKey) {
                $io->write('Extension: ' . $extensionKey, true);
                $errors = $this->lintPath(ExtensionManagementUtility::extPath($extensionKey), $extensionKey, $fail, $io);
                $errorsEncountered += $errors;
                if ($errors && $fail) {
                    break;
                }
            }
        }
        if ($errorsEncountered) {
            $io->error('Encountered ' . $errorsEncountered . ' Fluid parsing error(s)');
        } else {
            $io->success('All files OK');
        }
    }

    protected function reportError(Exception $error, SymfonyStyle $output): void
    {
        $output->warning($error->getMessage());
    }

    protected function lintPath(string $path, ?string $extensionKey, bool $fail, SymfonyStyle $output): int
    {
        $errors = 0;
        foreach ($this->collectFilesInPath($path) as $file) {
            $result = $this->lintFile((string) $file, $extensionKey, $output);
            if ($result) {
                $this->reportError($result, $output);
                ++$errors;
                if ($fail) {
                    break;
                }
            }
        }
        return $errors;
    }

    /**
     * Lints a file, returning either null on success or the
     * exception raised on error. Causes output via $output.
     *
     * @param string $file
     * @param string|null $extensionKey
     * @param OutputInterface $output
     * @return Exception|null
     */
    protected function lintFile(string $file, ?string $extensionKey, OutputInterface $output): ?Exception
    {
        $renderingContext = $this->getRenderingContext();
        if ($extensionKey) {
            $renderingContext->getTemplatePaths()->fillDefaultsByPackageName($extensionKey);
        }
        $parser = $renderingContext->getTemplateParser();
        try {
            $output->write('- file: ' . $file);
            $parser->parseFile($file);
            $output->write(' - OK!', true);
        } catch (Exception $error) {
            $output->write(' - FAIL', true);
            return $error;
        }
        return null;
    }

    protected function collectFilesInPath(string $path): iterable
    {
        $finder = new Finder();
        $finder->in($path)->name('*.html')->exclude(
            [
                'Tests',
                'examples',
                'typo3',
                'vendor',
                'public',
            ]
        )->notPath(
            [
                'Resources/Private/Templates/PageRenderer.html',
                'Resources/Private/Templates/MainPage.html',
            ]
        );
        return $finder->files();
    }

    protected function getRenderingContext(): RenderingContextInterface
    {
        return GeneralUtility::makeInstance(ObjectManager::class)->get(RenderingContext::class);
    }
}
