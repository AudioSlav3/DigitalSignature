<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use Wikimedia\ParamValidator\ParamValidator;
use MediaWiki\Context\RequestContext;
use MediaWiki\Html\Html;
use MediaWiki\Logger\LoggerFactory; 
											
class DigitalSignatureParserFunctions {
    /**
     * Parser function for #digital_signature.
     * Syntax: {{#digital_signature:group=<groupname>|user=<username>|show_changes=true|show_changes=true/false}}
     * @param Parser $parser
     * @param string ...$args
     * @return array
     */
    public static function onDigitalSignatureParserFunction( Parser $parser, ...$args ) {
        $parser->getOutput()->addModules( ['ext.DigitalSignature'] );

        $services = MediaWikiServices::getInstance();
        $user = RequestContext::getMain()->getUser();
        $title = $parser->getTitle();
        $pageId = $title->getArticleID();

        $wikiPageFactory = $services->getWikiPageFactory();
        $wikiPage = $wikiPageFactory->newFromTitle( $title );

        if ( !$wikiPage->exists() ) {
            return [ '<div class="error">Error: Page does not exist. Cannot display signature.</div>', 'noparse' => true, 'isHtml' => true ];
        }

        $latestRevision = $wikiPage->getRevisionRecord();
        $currentRevisionId = $latestRevision ? $latestRevision->getId() : 0;
        
        $output = '';

		// Parse arguments: group, user, show_changes
        $params = self::parseArgs( $args );
        $targetGroup = $params['group'] ?? null;
        $targetUser = $params['user'] ?? null;
		/* 
		====================================
		Open work to show changes
		------------------------------------
		$showChanges = isset( $params['show_changes'] ) && strtolower( $params['show_changes'] ) === 'true';
		====================================		
		*/
        

        // Determine the actual target for authorization
        $signingTargetType = null;
        $signingTargetValue = null;

        if ( $targetGroup !== null ) {
            $signingTargetType = 'group';
            $signingTargetValue = $targetGroup;
        } elseif ( $targetUser !== null ) {
            $signingTargetType = 'user';
            $signingTargetValue = $targetUser;
        } else {
            // Default to 'sysop' group if no target specified for backward compatibility or default behavior
            $signingTargetType = 'group';
            $signingTargetValue = 'sysop'; 
        }
        $signature = DigitalSignatureStore::getSignature( $pageId, $currentRevisionId );

        if ( $signature && $signature['ds_is_valid'] ) {
            $signerUser = $services->getUserFactory()->newFromId( $signature['ds_user_id'] );
            $signerName = $signerUser->getName();
            $timestamp = wfTimestamp( TS_MW, $signature['ds_timestamp'] );
            
																   
            $formattedTimestamp = $services->getContentLanguage()->userTimeAndDate( $timestamp, $user );

																	
            $userLinkWikitext = '[[User:' . $signerName . '|' . $signerName . ']]';

            $output .= '<div class="digital-signature-display">' .
                       '<h2>' . wfMessage( 'digitalsignature-title' )->plain() . '</h2>' .
                       '<p>' . wfMessage( 'digitalsignature-signed-by', $userLinkWikitext )->plain() . '</p>' .
                       '<p>' . wfMessage( 'digitalsignature-signed-date', $formattedTimestamp )->plain() . '</p>' .
                       '<p>' . wfMessage( 'digitalsignature-verification-hash', $signature['ds_content_hash'] )->plain() . '</p>';
            
            // Display remarks if they exist and are not empty
            if ( !empty( $signature['ds_remarks'] ) ) {
                $output .= '<p>' . wfMessage( 'digitalsignature-remarks-label' )->plain() . ': ' . htmlspecialchars( $signature['ds_remarks'] ) . '</p>';
            }

            $output .= '<p><small><i>' . wfMessage( 'digitalsignature-valid-signature-info' )->plain() . '</i></small></p>';
            $output .= '</div>'; // Close digital-signature-display div
        } else {
            $currentUser = RequestContext::getMain()->getUser();
            $userCanSign = false;

            if ( $currentUser->isRegistered() ) {
																  
                $userGroups = MediaWikiServices::getInstance()->getUserGroupManager()->getUserGroups( $currentUser );
				$currentUserName = $currentUser->getName();
                if ( $signingTargetType === 'group' && in_array( $signingTargetValue, $userGroups ) ) {
                    $userCanSign = true;
                } elseif ( $signingTargetType === 'user' && $currentUserName === $signingTargetValue ) {
                    $userCanSign = true;
                }
            }

            if ( $userCanSign ) {
                // Generate label and textarea using Html::element
                $remarksLabelHtml = Html::element( 'label', [ 'for' => 'digitalsignature-remarks-textarea-' . $pageId ], wfMessage( 'digitalsignature-remarks-label' )->plain() );
                $remarksTextareaHtml = Html::element( 'textarea', [
                    'id' => 'digitalsignature-remarks-textarea-' . $pageId,
                    'class' => 'digital-signature-remarks-textarea',
                    'rows' => '3',
                    'cols' => '50',
                    'placeholder' => wfMessage( 'digitalsignature-remarks-placeholder' )->plain()
                ], '' ); // Content of textarea is empty string

                // Concatenate the HTML for the remarks area
                $remarksAreaRawHtmlString = 
                    '<div class="digital-signature-remarks-area">' .
                    $remarksLabelHtml . '<br>' .
                    $remarksTextareaHtml .
                    '</div><br>'; // Close wrapper div and add a break

                // Use Parser::insertStripItem to ensure the HTML is not escaped
                $remarksAreaStrippedHtml = $parser->insertStripItem( $remarksAreaRawHtmlString );
                $output .= '<div class="digital-signature-button-container">' .
                           $remarksAreaStrippedHtml . 
                           '<span class="digital-signature-button" ' .
                           'role="button" tabindex="0" ' .
                           'data-page-id="' . $pageId . '" ' .
                           'data-rev-id="' . $currentRevisionId . '" ' .
                           'data-target-type="' . htmlspecialchars( $signingTargetType ) . '" ' .
						   'data-target-value="' . htmlspecialchars( $signingTargetValue ) . '" ' . 
						   '">' .
                           wfMessage( 'digitalsignature-sign-button' )->plain() .
                           '</span>' .
                           '<p class="digital-signature-message" style="display:none;"></p>' .
                           '</div>';
            } else {
				$awaitingMessage = '';
                if ($signingTargetType === 'group') {
                    $awaitingMessage = wfMessage( 'digitalsignature-awaiting-signature-group', htmlspecialchars( $signingTargetValue ) )->parse();
                } elseif ($signingTargetType === 'user') {
                    $awaitingMessage = wfMessage( 'digitalsignature-awaiting-signature-user', htmlspecialchars( $signingTargetValue ) )->parse();
                } else {
                    $awaitingMessage = wfMessage( 'digitalsignature-awaiting-signature-generic' )->parse();
                }
                $output .= '<div class="digital-signature-info">' .
                           '<p>' . $awaitingMessage . '</p>' .
                           '</div>';
            }
        }
        // Keep noparse => false to allow HTML to render
        return [ $output, 'noparse' => false, 'isHtml' => true ];
    }
	/**   
     * Helper function to parse arguments from the parser function.
     * Handles both positional and named arguments (e.g., "value" or "key=value").
     * @param array $args
     * @return array
     */
    private static function parseArgs( array $args ) {
        $parsedArgs = [];
        foreach ( $args as $arg ) {
            $parts = explode( '=', $arg, 2 );
            if ( count( $parts ) === 2 ) {
                $key = trim( $parts[0] );
                $value = trim( $parts[1] );
                $parsedArgs[$key] = $value;
            } else {
                // If it's a single value, assume it's the group name for backward compatibility
                // This might need refinement if other positional args are introduced.
                if ( !isset( $parsedArgs['group'] ) ) {
                    $parsedArgs['group'] = trim( $arg );
                }
            }
        }
        return $parsedArgs;
    }
}
