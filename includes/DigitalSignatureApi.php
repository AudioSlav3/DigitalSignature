<?php

use ApiBase;
use MediaWiki\MediaWikiServices;
use Wikimedia\ParamValidator\ParamValidator;
use MediaWiki\Page\WikiPageFactory;
use WikiPage; 
use MediaWiki\Logger\LoggerFactory; 

class DigitalSignatureApi extends ApiBase {

    public function execute() {
        $user = $this->getUser();

        if ( !$user->isRegistered() ) {
            $this->dieWithError( 'apierror-mustbeloggedin', 'notloggedin' );
        }

        $params = $this->extractRequestParams();
        $pageId = (int)$params['pageid'];
        $revId = (int)$params['revid'];
        $groupName = $params['group'] ?? null;
		$userName = $params['user'] ?? null;
		$remarks = $params['remarks'] ?? null;

        $title = MediaWikiServices::getInstance()->getTitleFactory()->newFromId( $pageId );
        if ( !$title || !$title->exists() ) {
            $this->dieWithError( 'apierror-nosuchpage', 'nosuchpage' );
        }

        $services = MediaWikiServices::getInstance();
        $wikiPageFactory = $services->getWikiPageFactory();
        $wikiPage = $wikiPageFactory->newFromTitle( $title );
        
        if ( !$wikiPage->exists() ) {
            $this->dieWithError( 'The target page does not exist or has been deleted.', 'nosuchpage' );
        }

        $latestRevision = $wikiPage->getRevisionRecord();
        $latestRevId = $latestRevision ? $latestRevision->getId() : 0;

        if ( $revId !== $latestRevId ) {
            $this->dieWithError(
                'The page content has changed since the signature request was initiated. Please refresh the page and try again.',
                'contentchanged'
            );
        }

        // Authorization logic based on group or user
        $authorized = false;
        if ( $groupName !== null ) {
			$userGroups = MediaWikiServices::getInstance()->getUserGroupManager()->getUserGroups( $user );
			if ( in_array( $groupName, $userGroups ) ) {
				$authorized = true;
				}
			} elseif ( $userName !== null ) {
				if ( $user->getName() === $userName ) {
					$authorized = true;
				}
			} else {
				// Default to 'sysop' if neither group nor user is specified (for backward compatibility)
				$userGroups = MediaWikiServices::getInstance()->getUserGroupManager()->getUserGroups( $user );
				if ( in_array( 'sysop', $userGroups ) ) {
					$authorized = true;
				}
			}

			if ( !$authorized ) {
				$this->dieWithError( 'You are not authorized to sign pages with the specified criteria.', 'permissiondenied' );
			}
        $contentHash = DigitalSignatureStore::getContentHashForRevision( $revId );
        if ( !$contentHash ) {
            $this->dieWithError( 'Could not retrieve content hash for the specified revision.', 'nohash' );
        }

        $success = DigitalSignatureStore::addSignature( $pageId, $revId, $user->getId(), $contentHash, $remarks );

        if ( $success ) {
            // Force MediaWiki to clear the parser cache for this page after successful signature
            // This makes the page re-render from scratch on next view, picking up the new signature.
            $wikiPage->doPurge();
            // Use LoggerFactory for logging
            LoggerFactory::getInstance( 'DigitalSignature' )->info( "DigitalSignature: Page ID $pageId purged from parser cache after successful signing." );

            $this->getResult()->addValue( null, $this->getModuleName(), [
                'result' => 'success',
                'pageid' => $pageId,
                'revid' => $revId,
                'userid' => $user->getId(),
                'hash' => $contentHash,
                'remarks' => $remarks
            ] );
        } else {
            $this->dieWithError( 'Failed to store digital signature.', 'dberror' );
        }
    }

    /**
     * @return array
     */
    public function getAllowedParams() {
        return [
            'pageid' => [
                ParamValidator::PARAM_TYPE => 'integer',
                ParamValidator::PARAM_REQUIRED => true,
                'help-msg' => 'The ID of the page to sign.'
            ],
            'revid' => [
                ParamValidator::PARAM_TYPE => 'integer',
                ParamValidator::PARAM_REQUIRED => true,
                'help-msg' => 'The revision ID of the page to sign.'
            ],
            'group' => [
                ParamValidator::PARAM_TYPE => 'string',
                ParamValidator::PARAM_REQUIRED => false,
                'help-msg' => 'The user group authorized to sign this page.'
            ],
            'user' => [ 
                ParamValidator::PARAM_TYPE => 'string',
                ParamValidator::PARAM_REQUIRED => false,
                'help-msg' => 'The specific username authorized to sign this page.'
            ],
            'remarks' => [
                ParamValidator::PARAM_TYPE => 'string',
                ParamValidator::PARAM_REQUIRED => false,
                'help-msg' => 'Optional remarks for the digital signature.'
            ]
        ];
    }

    /**
     * @return array
     */
    protected function getExamples() {
        return [
            'api.php?action=digitalsignature&pageid=123&revid=456&group=sysop',
            'api.php?action=digitalsignature&pageid=123&revid=456&user=AdminUser',
			'api.php?action=digitalsignature&pageid=123&revid=456&group=sysop&remarks=Approved+by+management',
        ];
    }
}
