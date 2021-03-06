<?php

declare(strict_types=1);

namespace TYPO3\Darth\Command;

/*
 * This file is part of the TYPO3 project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use TYPO3\Darth\Application;
use TYPO3\Darth\GitHelper;

/**
 * Adds a signed "RELEASE" commit with modified versions, pushes the change to gerrit, auto-approves and then adds
 * a tag and pushes them to the remote git repository as well.
 *
 * FYI - remote branches still need to be created manually
 */
class ReleaseCommand extends Command
{
    /**
     * @var SymfonyStyle
     */
    private $io;

    /**
     * @var GitHelper
     */
    private $gitHelper;

    /**
     * {@inheritdoc}
     */
    public function configure()
    {
        $this
            ->addArgument(
                'version',
                InputArgument::REQUIRED,
                'Set it to "8.7" to release the next "8.7.x" version (even if "8.7.0" has not been released). Checks for a branch named like TYPO3_8-7 or uses master'
            )
            ->addOption(
                'commitMessage',
                'm',
                InputOption::VALUE_OPTIONAL,
                'Additional commit message to [RELEASE] Release of TYPO3 x.y.z'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'If this option is set, nothing will be pushed to the origin repository'
            )
            ->addOption(
                'interactive',
                'i',
                InputOption::VALUE_OPTIONAL,
                'If this option is set, the user will be prompted, enabled by default',
                true
        );
    }

    /**
     * {@inheritdoc}
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->io->title('The day has come - it is release time! You have just pressed the button');
        $this->gitHelper = new GitHelper($this->getApplication()->getWorkingDirectory(), $this->io->isVerbose());

        $dryRun = $input->hasOption('dry-run') && $input->getOption('dry-run') != false;
        $isInteractive = $input->hasOption('interactive') && $input->getOption('interactive') != false;
        $givenVersion = $input->getArgument('version');
        // Evaluate if "version" has only "9" or "v9"
        $givenVersion = ltrim($givenVersion, 'v');

        $workingDirectory = $this->getApplication()->getWorkingDirectory();

        $this->io->writeln('Cleaning up the current local git repository.');
        $git = $this->gitHelper->initializeCleanWorkingCopy();

        $this->io->writeln('Detecting the version that should be released.');
        $nextVersion = $this->gitHelper->findNextVersion($givenVersion);
        $remoteBranchWithRemoteName = $this->gitHelper->findRemoteBranch($nextVersion);

        list(, $remoteBranch) = explode('/', $remoteBranchWithRemoteName);
        $this->io->note(
            'The next version will be ' . $nextVersion . "\n" .
            'The branch to be used with is ' . $remoteBranchWithRemoteName
        );

        if ($isInteractive) {
            $answer = $this->io->confirm('Is the information above correct?', true);
            if (!$answer) {
                return 1;
            }
        }

        // Create a new local branch tracking the remote branch
        $localBranchName = 'release-' . date('Ymd-His') . '-v' . str_replace('.', 'dot', $nextVersion);
        $git->checkout('-b', $localBranchName, '--track', $remoteBranchWithRemoteName);

        $commitHashBeforeRelease = $this->gitHelper->getCurrentRevision();
        if ($this->io->isVerbose()) {
            $this->io->writeln('HEAD is now at a new branch ' . $localBranchName . ' with at ' . $commitHashBeforeRelease);
        }

        $filesToManipulate = $this->getApplication()->getConfiguration('updateFiles');
        if (is_array($filesToManipulate)) {
            $this->updateFilesWithVersions($workingDirectory, $filesToManipulate, $nextVersion);
        }

        // Now commit with "[RELEASE] Released TYPO3 v.x.x"
        $commitMessage = '[RELEASE] Release of TYPO3 ' . $nextVersion;
        if ($input->hasOption('commitMessage') && $input->getOption('commitMessage')) {
            $commitMessage .= "\n\n" . $input->getOption('commitMessage');
        }
        $git->commit('-a', '-S', '--allow-empty', '-m', trim($commitMessage));
        $localReleaseCommitHash = $this->gitHelper->getCurrentRevision();
        $this->io->success('Release commit is ' . $localReleaseCommitHash . ' with message ' . "\n\n" . $commitMessage);

        if ($isInteractive) {
            $answer = $this->io->confirm('Everything is set up locally, are you ready to push to remote?' . ($dryRun ? ' (don\'t worry, you\'re in dry-run mode - nothing will happen anyways)' : ''), true);
            if (!$answer) {
                return 1;
            }
        }

        // Push to remote origin with /RELEASE topic
        $this->io->note('Pushing commit ' . $localReleaseCommitHash . ' to gerrit, and auto-approve this commit.');
        if (!$dryRun) {
            $this->gitHelper->pushAndApproveWithGerrit($remoteBranch . '/RELEASE', $localReleaseCommitHash);

            // Commit is pushed, now wait until gerrit and the remote git repository are in sync again
            $git->reset('--hard', $remoteBranchWithRemoteName); // now it should be back to $commitHashBeforeRelease
            $git->pull();
            $attempts = 0;
            while ($this->gitHelper->getCurrentRevision() === $commitHashBeforeRelease) {
                $git->pull();
                $this->io->note('Waiting for remote git repository to be updated. Attempt #' . ($attempts+1));
                sleep(10);
                if (++$attempts > 60) {
                    $this->io->error('We waited, but this infrastructure is too slow. So now you have to do the rest manually. '
                        . 'This means: Run "git pull" in your working directory until you see the RELEASE commit, and get the SHA1 of that commit'
                        . 'Afterwards, add a signed tag (v1.2.3, 1.2.3 and TYPO3_1-2-3) manually and do a `git push origin $tagName` for each tag.');

                    return 1;
                }
            }
        } else {
            $this->io->comment('Skipped!');
        }

        // add a "tag" to it and sign that one
        // The tag is called "v1.2.3"
        $tagsToAdd = ['v' . $nextVersion];

        // This part can be removed once TYPO3 v8 support is removed
        if (version_compare($nextVersion, '9.0.0') < 0) {
            $tagsToAdd[] = $nextVersion;    // Adding "8.7.3"
            $tagsToAdd[] = 'TYPO3_' . str_replace('.', '-', $nextVersion);    // Adding "TYPO3_8-7-3"
        }

        // Create tags
        $tagMessage = 'Release of TYPO3 ' . $nextVersion;
        foreach ($tagsToAdd as $tagName) {
            $git->tag('-s', '-f', '-m', $tagMessage, $tagName);
        }
        $this->io->success('Added the signed tag(s): ' . implode(', ', $tagsToAdd));

        // push tags
        foreach ($tagsToAdd as $tagName) {
            $this->io->success('Pushing tag ' . $tagName . ' to origin');
            if (!$dryRun) {
                $git->push('origin', $tagName);
            } else {
                $this->io->comment('Skipped!');
            }
        }

        // now change the versions again, with the planned next version
        // if it is a "9.0.1" release, it's gonna be "9.0.1-dev"
        $versionParts = explode('.', $nextVersion, 3);
        ++$versionParts[2];
        $upcomingVersion = implode('.', $versionParts);
        $this->updateFilesWithVersions($workingDirectory, $filesToManipulate, $upcomingVersion, $nextVersion);

        $commitMessage = '[TASK] Set TYPO3 version to ' . $upcomingVersion . '-dev';
        $this->io->note('Committing ' . $commitMessage . ' with the latest updates to set the next version.');
        $git->commit('-a', '--allow-empty', '-m', $commitMessage);

        $this->io->note('Pushing commit ' . $localReleaseCommitHash . ' to gerrit, and auto-approve this commit.');
        if (!$dryRun) {
            $this->gitHelper->pushAndApproveWithGerrit($remoteBranch . '/RELEASE', $localReleaseCommitHash);
        } else {
            $this->io->comment('Skipped!');
        }

        $this->io->success('Release is done, now go on with packaging by using the "package" command');

        return 0;
    }

    /**
     * Goes through the source code and updates the code where it applies.
     *
     * @param string $workingDirectory
     * @param array  $configuration
     * @param string $nextVersion
     * @param string $currentVersion      current version, used "-dev" flag for replacements
     */
    protected function updateFilesWithVersions(string $workingDirectory, array $configuration, string $nextVersion, string $currentVersion = null)
    {
        $versionParts = explode('.', $nextVersion);
        $nextMinorVersion = $versionParts[0] . '.' . $versionParts[1];
        $firstBugfixVersion = $nextMinorVersion . '.0';

        // now find the files you want to modify
        foreach ($configuration as $fileDetails) {
            $finder = new Finder();
            $finder->name(basename($fileDetails['file']))
                ->ignoreUnreadableDirs()
                ->in($workingDirectory . '/' . dirname($fileDetails['file']));

            foreach ($finder as $foundFile) {
                $fileContents = $foundFile->getContents();
                $updatedFileContents = $fileContents;
                switch ($fileDetails['type']) {
                    case 'nextBugfixVersion':
                        if (!$currentVersion) {
                            continue;
                        }
                        // just replace the just released version with the latest version
                        $updatedFileContents = str_replace($currentVersion, $nextVersion, $fileContents);
                        break;
                    case 'bugfixVersion':
                        // just replace it with the latest version
                        $updatedFileContents = preg_replace_callback('/' . $fileDetails['pattern'] . '/u', function ($matches) use ($nextVersion) {
                            return str_replace($matches[1], $nextVersion, $matches[0]);
                        }, $fileContents);
                        break;
                    case 'nextDevVersion':
                        if (!$currentVersion) {
                            continue;
                        }
                        // just replace the pattern with "1.2.3-dev"
                        $updatedFileContents = preg_replace_callback('/' . $fileDetails['pattern'] . '/u', function ($matches) use ($nextVersion) {
                            return str_replace($matches[1], $nextVersion . '-dev', $matches[0]);
                        }, $fileContents);
                        break;
                    case 'minorVersion':
                        // just replace it with the latest version
                        $updatedFileContents = preg_replace_callback('/' . $fileDetails['pattern'] . '/u', function ($matches) use ($nextMinorVersion) {
                            return str_replace($matches[1], $nextMinorVersion, $matches[0]);
                        }, $fileContents);
                        break;
                    case 'bugfixRange':
                        // used in ext_emconf.php
                        $updatedFileContents = preg_replace_callback('/' . $fileDetails['pattern'] . '/u', function ($matches) use ($firstBugfixVersion, $nextVersion) {
                            $result = str_replace($matches[1], $firstBugfixVersion, $matches[0]);

                            return str_replace($matches[2], $nextVersion, $result);
                        }, $fileContents);
                        break;
                }

                if ($fileContents !== $updatedFileContents && $updatedFileContents !== false) {
                    file_put_contents((string) $foundFile, $updatedFileContents);
                    if ($this->io->isVerbose()) {
                        $this->io->writeln('Updated ' . $fileDetails['type'] . ' for file ' . $foundFile);
                    }
                }
            }
        }
    }

    /**
     * Stub for allowing proper IDE support.
     *
     * @return \Symfony\Component\Console\Application|Application
     */
    public function getApplication()
    {
        return parent::getApplication();
    }
}
