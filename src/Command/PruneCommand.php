<?php

namespace Wikisource\IaUpload\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PruneCommand extends CommandBase {

	/**
	 * Set name and job.
	 */
	protected function configure() {
		$this->setName( 'prune' )
			->setDescription( 'Deletes old job queue items' );
	}

	/**
	 * @param InputInterface $input An InputInterface instance
	 * @param OutputInterface $output An OutputInterface instance
	 * @return null|int null or 0 if everything went fine, or an error code
	 */
	protected function execute( InputInterface $input, OutputInterface $output ) {
		foreach ( $this->getJobs() as $jobFile ) {
			$oneWeekAgo = time() - ( 60 * 60 * 24 * 7 );
			if ( filemtime( $jobFile ) < $oneWeekAgo ) {
				// If modified more than a week ago, delete the job directory.
				$this->deleteDirectory( dirname( $jobFile ) );
				if ( $output->isVerbose() ) {
					$output->writeln( 'Deleted ' . dirname( $jobFile ) );
				}
			}
		}
		return Command::SUCCESS;
	}
}
