<?php

use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Hook\EditPage__attemptSave_afterHook;
use MediaWiki\Installer\Installer;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

class DigitalSignatureHooks implements
    EditPage__attemptSave_afterHook
{
    private static $logger;

    public static function init() {
        self::$logger = LoggerFactory::getInstance( 'DigitalSignature' );
    }

    /**
     * Registers the parser function.
     * Changed to static method, accepts $parser by reference, returns bool.
     * @param Parser &$parser
     * @return bool
     */
    public static function onParserFirstCallInit( &$parser ): bool {
        self::init();
        self::$logger->debug( 'DigitalSignature: onParserFirstCallInit hook fired.' );
        $parser->setFunctionHook(
            'digital_signature',
            [ DigitalSignatureParserFunctions::class, 'onDigitalSignatureParserFunction' ]
        );
        self::$logger->debug( 'DigitalSignature: Parser function #digital_signature registered.' );
        return true;
    }

    /**
     * Hook to invalidate signatures when a page is saved.
     * This hook is called after EditPage attempts to save.
     * @param EditPage $editPage_Obj The EditPage object.
     * @param Status $status The status object of the save operation.
     * @param array $resultDetails Additional details about the edit result.
     */
    public function onEditPage__attemptSave_after( $editPage_Obj, $status, $resultDetails ): void {
        self::init();
        self::$logger->info( 'DigitalSignature: onEditPage__attemptSave_after hook fired.' );

        // Log save status
        if ( !$status->isOK() ) {
            self::$logger->warning( 'DigitalSignature: Page save failed. Status: ' . $status->getWikiText() );
            return;
        } else {
            self::$logger->info( 'DigitalSignature: Page save successful. Proceeding with invalidation.' );
        }

        $wikiPage = $editPage_Obj->getArticle();
        $revisionRecord = $wikiPage->getRevisionRecord();

        if ( !$wikiPage || !$revisionRecord ) {
            self::$logger->error( 'DigitalSignature: Could not retrieve WikiPage or RevisionRecord in onEditPage__attemptSave_after. Page ID: ' . ( $editPage_Obj->getArticle() ? $editPage_Obj->getArticle()->getId() : 'N/A' ) );
            return;
        }

        $pageId = $wikiPage->getId();
        $newRevId = $revisionRecord->getId();

        self::$logger->info( "DigitalSignature: Attempting to invalidate signatures for page ID $pageId. New Revision ID: $newRevId." );
        // Capture and log the number of affected rows from the invalidation
        $affectedRows = DigitalSignatureStore::invalidateSignaturesForPage( $pageId );

        if ( $affectedRows > 0 ) {
            self::$logger->info( "DigitalSignature: Signatures for page ID $pageId successfully invalidated. Affected rows: $affectedRows." );
        } else {
            self::$logger->warning( "DigitalSignature: No signatures invalidated for page ID $pageId. Affected rows: $affectedRows." );
        }

        // Force MediaWiki to clear the parser cache for this page. This makes the page re-render from scratch on next view, picking up the updated signature status.
        $wikiPage->doPurge();
        self::$logger->info( "DigitalSignature: Page ID $pageId purged from parser cache." );
    }

    /**
     * Hook for database schema updates.
     * This hook directly creates the mw_digital_signatures table if it doesn't exist.
     * @param Installer $updater
     * @return void
     */
    public static function onLoadExtensionSchemaUpdates( $updater ) {
        self::init();
        error_log( 'DigitalSignature: onLoadExtensionSchemaUpdates hook fired.' );
        self::$logger->debug( 'DigitalSignature: onLoadExtensionSchemaUpdates hook fired.' );

        $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
        $tableName = 'mw_digital_signatures';

        // Only attempt to create if table doesn't exist
        if ( !$dbw->tableExists( $tableName ) ) {
            $sqlFilePath = __DIR__ . '/../sql/mw_digital_signatures.sql';
			
			self::$logger->debug( 'DigitalSignature: Attempting to read SQL file from: ' . $sqlFilePath );

            if ( file_exists( $sqlFilePath ) ) {
                self::$logger->debug( 'DigitalSignature: Found SQL file at: ' . $sqlFilePath . ' for direct execution.' );
                $sqlContent = file_get_contents( $sqlFilePath );

                if ( $sqlContent === false ) {
                    self::$logger->error( 'DigitalSignature: Failed to read SQL file content: ' . $sqlFilePath );
                    return;
                } elseif ( empty( trim( $sqlContent ) ) ) { // Check if content is empty or just whitespace
					self::$logger->error( 'DigitalSignature: SQL file content is empty or contains only whitespace: ' . $sqlFilePath );
					return;
				} else {
					self::$logger->debug( 'DigitalSignature: Successfully read SQL file content. ' );					
				}

                // Replace /*$wgDBTableOptions*/ with actual table options
                global $wgDBTableOptions;
                $sqlContent = str_replace( '/*$wgDBTableOptions*/', $wgDBTableOptions, $sqlContent );
				self::$logger->debug( 'DigitalSignature: SQL content after replacements. Length: ' . strlen( $sqlContent ) );

                try {
                    // Execute the SQL content directly
                    $dbw->query( $sqlContent, __METHOD__ );
                    self::$logger->info( "DigitalSignature: Successfully created table '$tableName' via direct SQL execution." );
                } catch ( Exception $e ) {
                    self::$logger->fatal( "DigitalSignature: Failed to create table '$tableName' via direct SQL: " . $e->getMessage() );
					self::$logger->fatal( "DigitalSignature: Full SQL attempted to run: " . $sqlContent );
                }
            } else {
                self::$logger->error( 'DigitalSignature: SQL file NOT FOUND at: ' . $sqlFilePath );
            }
        } else {
            self::$logger->info( "DigitalSignature: Table '$tableName' already exists. Skipping creation." );
        }
    }
}

DigitalSignatureHooks::init(); // Initialize the logger when the class is loaded
