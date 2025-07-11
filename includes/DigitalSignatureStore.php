<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Logger\LoggerFactory;

class DigitalSignatureStore {
    private static $logger; 

    public static function init() {
        self::$logger = LoggerFactory::getInstance( 'DigitalSignature' );
    }

    /**
     * Retrieves a signature for a given page and revision.
     * @param int $pageId
     * @param int $revId
     * @return array|null Signature data if found, otherwise null.
     */
    public static function getSignature( $pageId, $revId ) {
        self::init(); // Ensure logger is initialized
        $dbr = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
        $res = $dbr->selectRow(
            'mw_digital_signatures',
            '*',
            [
                'ds_page_id' => $pageId,
                'ds_rev_id' => $revId,
                'ds_is_valid' => 1 // Crucial: Only retrieve valid signatures
            ],
            __METHOD__
        );
        if ($res) {
            self::$logger->debug("DigitalSignatureStore: Found valid signature for page ID $pageId, rev ID $revId.");
            return (array)$res;
        } else {
            self::$logger->debug("DigitalSignatureStore: No valid signature found for page ID $pageId, rev ID $revId.");
            return null;
        }
    }

    /**
     * Stores a new digital signature.
     * @param int $pageId
     * @param int $revId
     * @param int $userId
     * @param string $contentHash
	 * @param string|null $remarks Optional remarks for the signature.
     * @return bool True on success, false on failure.
     */
    public static function addSignature( $pageId, $revId, $userId, $contentHash, $remarks = null ) {
        self::init(); // Ensure logger is initialized
        $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
        $timestamp = $dbw->timestamp();

        self::$logger->info("DigitalSignatureStore: Attempting to add signature for page ID $pageId, rev ID $revId by user $userId. Remarks: " . ($remarks ?? '[none]'));

        // Invalidate all existing valid signatures for this page before adding a new one
        $invalidatedCount = self::invalidateSignaturesForPage( $pageId );
        self::$logger->info("DigitalSignatureStore: Invalidation before add affected $invalidatedCount rows for page ID $pageId.");

        $success = $dbw->insert(
            'mw_digital_signatures',
            [
                'ds_page_id' => $pageId,
                'ds_rev_id' => $revId,
                'ds_user_id' => $userId,
                'ds_timestamp' => $timestamp,
                'ds_content_hash' => $contentHash,
                'ds_is_valid' => 1,
                'ds_remarks' => $remarks
            ],
            __METHOD__
        );

        if ($success) {
            self::$logger->info("DigitalSignatureStore: Successfully added new signature for page ID $pageId, rev ID $revId.");
        } else {
            self::$logger->error("DigitalSignatureStore: Failed to add new signature for page ID $pageId, rev ID $revId.");
        }
        return $success;
    }

    /**
     * Invalidates all signatures for a given page.
     * @param int $pageId
     * @return int The number of affected rows.
     */
    public static function invalidateSignaturesForPage( $pageId ) {
        self::init(); // Ensure logger is initialized
        $dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
        
        self::$logger->info("DigitalSignatureStore: Starting invalidation for page ID $pageId.");

        $updateConditions = [ 'ds_page_id' => $pageId, 'ds_is_valid' => 1 ];
        $updateFields = [ 'ds_is_valid' => 0 ];

        // Perform the update
        $dbw->update(
            'mw_digital_signatures',
            $updateFields,
            $updateConditions,
            __METHOD__
        );
        
        // Get the number of affected rows
        $affectedRows = $dbw->affectedRows();

        self::$logger->info("DigitalSignatureStore: Invalidation query for page ID $pageId completed. Affected rows: $affectedRows.");
        return $affectedRows;
    }

    /**
     * Generates a hash for the content of a specific revision.
     * MediaWiki provides content hashing utilities.
     * @param int $revId
     * @return string|null The content hash, or null if revision not found.
     */
    public static function getContentHashForRevision( $revId ) {
        self::init(); // Ensure logger is initialized
        $services = MediaWikiServices::getInstance();
        $revisionLookup = $services->getRevisionLookup();
        $revision = $revisionLookup->getRevisionById( $revId );

        if ( !$revision ) {
            self::$logger->warning("DigitalSignatureStore: Revision not found for ID $revId when attempting to get content hash.");
            return null;
        }

        // Specify the main slot when getting content using SlotRecord::MAIN
        $content = $revision->getContent( SlotRecord::MAIN );
        if ( !$content ) {
            self::$logger->warning("DigitalSignatureStore: No content found in main slot for revision ID $revId.");
            return null;
        }

        // Use getText() to get the raw wikitext. Will be used in the SHA-1 hash. 
        $text = null;
        if ( $content instanceof WikitextContent ) {
            $text = $content->getText();
            self::$logger->debug("DigitalSignatureStore: Retrieved wikitext content for rev ID $revId. Length: " . strlen($text));
        } else {
            self::$logger->warning("DigitalSignatureStore: Content for rev ID $revId is not WikitextContent. Cannot get textual hash.");
            return null; 
        }

        if ( $text === null ) {
            self::$logger->warning("DigitalSignatureStore: Text content is null for rev ID $revId. Cannot generate hash.");
            return null; // Content has no textual representation to hash
        }

        // Use a standard SHA-1 hash on the raw text
        $hash = sha1( $text );
        self::$logger->debug("DigitalSignatureStore: Generated hash for rev ID $revId: $hash");
        return $hash;
    }

    /**
     * Retrieves the raw text content of a specific revision.
     * @param int $revId
     * @return string|null The raw text content, or null if revision not found or content is not text.
     */
    public static function getContentForRevision( $revId ) {
        self::init();
        $services = MediaWikiServices::getInstance();
        $revisionLookup = $services->getRevisionLookup();
        $revision = $revisionLookup->getRevisionById( $revId );

        if ( !$revision ) {
            self::$logger->warning("DigitalSignatureStore: Revision not found for ID $revId when attempting to get content.");
            return null;
        }

        $content = $revision->getContent( SlotRecord::MAIN );
        if ( !$content ) {
            self::$logger->warning("DigitalSignatureStore: No content found in main slot for revision ID $revId.");
            return null;
        }

        // Check if the content is of a type that can be converted to text
        if ( $content instanceof WikitextContent ) {
            $text = $content->getText();
            self::$logger->debug("DigitalSignatureStore: Retrieved raw wikitext content for rev ID $revId. Length: " . strlen($text));
            return $text;
        } else {
            self::$logger->warning("DigitalSignatureStore: Content for rev ID $revId is not WikitextContent. Cannot retrieve raw text.");
            return null;
        }
    }
}
DigitalSignatureStore::init(); // Initialize the logger when the class is loaded
