<?php

namespace IaUpload\DjvuMakers;

use Exception;
use pastuhov\Command\Command;
use pastuhov\Command\CommandException;
use ZipArchive;

/**
 * This class creates a DjVu file from the `*_djvu.xml` and `*_jp2.zip` files in an Internet
 * Archive item.
 * @package IaUpload
 */
class Jp2DjvuMaker extends DjvuMaker {

	/**
	 * @inheritdoc
	 */
	public function createLocalDjvu() {
		$this->downloadFiles();
		$jp2Directory = $this->unzipDownloadedJp2Archive();
		$djvuFile = $this->convertJp2ToDjvu( $jp2Directory );
		$this->addXmlToDjvu( $djvuFile );
		return $djvuFile;
	}

	/**
	 * Download metadata, xml, and zip files to the job directory. Check for each before
	 * downloading, in case this has been previously interrupted.
	 */
	protected function downloadFiles() {
		// Metadata.
		$metadataFile = $this->jobDir() . '/metadata.json';
		if ( !file_exists( $metadataFile ) ) {
			$this->log->info( "Saving IA metadata to $metadataFile" );
			$metadata = $this->iaClient->fileDetails( $this->itemId );
			file_put_contents( $metadataFile, \GuzzleHttp\json_encode( $metadata ) );
		}
		if ( !isset( $metadata ) ) {
			$metadata = \GuzzleHttp\json_decode( file_get_contents( $metadataFile ), true );
		}

		// Other files (JP2 and DjVu XML).
		$filesToDownload = preg_grep( '/.*(_djvu.xml|_jp2.zip)/', array_keys( $metadata['files'] ) );
		foreach ( $filesToDownload as $file ) {
			if ( !file_exists( $this->jobDir() . $file ) ) {
				$this->log->info( "Downloading $this->itemId$file" );
				$this->iaClient->downloadFile( $this->itemId . $file, $this->jobDir() . $file );
			}
		}
	}

	/**
	 * Unzip the _jp2.zip file to `<item>_jp2/` (which is the top-level directory that's within the
	 * zip) in the job directory.
	 * @return string The full path of the _jp2 directory, with no trailing slash.
	 * @throws Exception If the zip file doesn't exist.
	 */
	protected function unzipDownloadedJp2Archive() {
		$zipFiles = glob( $this->jobDir().'/*_jp2.zip' );
		if ( count( $zipFiles ) == 0 ) {
			throw new Exception( "Zip file not found" );
		}
		$zipFile = array_shift( $zipFiles );
		$this->log->info( "Unzipping $zipFile" );
		$zip = new ZipArchive();
		$zip->open( $zipFile );

		$outDir = dirname( $zipFile ) . '/' . pathinfo( $zipFile, PATHINFO_FILENAME );
		if ( is_dir( $outDir ) ) {
			// Directory already exists; check contents.
			$numInDir = count( glob( "$outDir/*.jp2" ) );
			$numInZip = $zip->numFiles - 1; // Minus 1 for the top-level directory.
			if ( $numInDir === $numInZip ) {
				// All done.
				return $outDir;
			}
		}

		// Extract file.
		$zip->extractTo( $this->jobDir() );
		$this->log->debug( "Zip file extracted to $outDir" );
		return $outDir;
	}

	/**
	 * Convert each JP2 file to a DjVu in the same directory.
	 * @param string $itemDir The directory containing the JP2 files, with no trailing slash.
	 * @return string The full path to the single combined DjVu file.
	 * @throws Exception
	 */
	protected function convertJp2ToDjvu( $itemDir ) {
		$this->log->info( "Processing JP2 files" );
		$jp2Files = glob( $itemDir . '/*.jp2' );
		if ( count( $jp2Files ) === 0 ) {
			throw new Exception( "No JP2 file found in " . $itemDir );
		}
		$djvuFiles = [];
		$this->log->info( "Converting " . count( $jp2Files ) . " individual JP2s to DjVus" );
		// Create a destination directory in which to create the intermediate files.
		$buildDir = $this->jobDir() . '/build';
		if ( !is_dir( $buildDir ) ) {
			mkdir( $buildDir );
		}
		foreach ( $jp2Files as $jp2FileNum => $jp2File ) {
			$this->log->debug( "Converting $jp2File..." );

			// Create Jpeg of this page.
			$jpgFile = $buildDir . '/' . $this->itemId . '_p' . $jp2FileNum . '.jpg';
			if ( !file_exists( $jpgFile ) ) {
				$this->log->debug( "...to $jpgFile" );
				$convertArgs = "-resize 1500x1500 \"$jp2File\" \"$jpgFile\"";
				$this->runCommand( 'convert', $convertArgs );
			}

			// Make DjVu file of this page. Use the item identifier as the filename instead of
			// matching the JP2 so we can later modify the XML more easily.
			$djvuFile = $buildDir . '/' . $this->itemId . '_p' . $jp2FileNum . '.djvu';
			if ( !file_exists( $djvuFile ) ) {
				$this->log->debug( "...to $djvuFile" );
				$this->runCommand( "c44", " \"$jpgFile\" \"$djvuFile\"" );
			}
			$djvuFiles[] = $djvuFile;
		}

		// Merge all DjVu files into one.
		$singleDjvuFile = $this->jobDir().'/'.$this->itemId.'.djvu';
		if ( !file_exists( $singleDjvuFile ) ) {
			$this->log->info( "Merging all DjVu files to $singleDjvuFile" );
			$djvuFileList = '"' . join( '" "', $djvuFiles ) . '"';
			$this->runCommand( "djvm", "-c \"$singleDjvuFile\" " . $djvuFileList );
		}
		return $singleDjvuFile;

	}

	/**
	 * @param string $djvuFile Full path to the main DjVu file.
	 * @throws Exception
	 */
	public function addXmlToDjvu( $djvuFile ) {
		$djvuXmlFiles = glob( $this->jobDir() . '/*_djvu.xml' );
		$djvuXmlFile = array_shift( $djvuXmlFiles );
		if ( !file_exists( $djvuXmlFile ) ) {
			throw new Exception( "File not found: $djvuXmlFile" );
		}

		$newDjvuXmlFile = $djvuXmlFile . '_new.xml';
		if ( file_exists( $newDjvuXmlFile ) ) {
			// Assume that if the modified XML file exists, it's also been merged into the DjVu.
			return;
		}

		// Replace URLs in the XML. Every OBJECT element has a 'data' attribute which is a URI.
		$this->log->info( "Modifying DjVu XML file $djvuXmlFile to add $djvuFile" );
		$xml = simplexml_load_file( $djvuXmlFile, null, LIBXML_NOENT );
		$pageNum = 0;
		foreach ( $xml->BODY->OBJECT as $object ) {
			$object['data'] = 'file://localhost'.$djvuFile;
			// The first PARAM is always 'PAGE'.
			$object->PARAM[0]['value'] = $this->itemId . '_p' . $pageNum . '.djvu';
			$pageNum++;
		}
		// Save modified XML to a new file.
		$xml->asXML( $newDjvuXmlFile );

		// Modify the DjVu file with the contents of the XML file. (The target DjVu file is the file
		// referenced by the OBJECT element of the XML file).
		$this->log->info( "Merging modified XML into full DjVu file" );
		$this->runCommand( 'djvuxmlparser', '"'. $newDjvuXmlFile . '"' );
	}

	/**
	 * Run an external command, first checking that it exists by using 'which'.
	 * @param string $command The command to run.
	 * @param string $args The arguments to the command. Will be appended to the command as-is.
	 * @throws Exception If the command can't be found.
	 */
	protected function runCommand( $command, $args ) {
		if ( !Command::exec( "which $command" ) ) {
			throw new Exception( "Command $command not found" );
		}
		$commandOutput = Command::exec( "$command $args" );
		if ( $commandOutput ) {
			$this->log->debug( $commandOutput );
		}
	}
}
