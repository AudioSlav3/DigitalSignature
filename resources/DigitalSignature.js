( function ( $, mw ) {
    

    $( function () {
        console.log("DigitalSignature.js: Document ready. Initializing...");
        
        // Find all digital signature buttons on the page
        $( '.digital-signature-button' ).on( 'click', function () {
            console.log("DigitalSignature.js: Button clicked!"); // Log when button is clicked

            const $button = $( this );
            const $container = $button.closest( '.digital-signature-button-container' );
            const $messageArea = $container.find( '.digital-signature-message' );
            const $remarksTextarea = $container.find( '.digital-signature-remarks-textarea' ); 

            console.log( '$remarksTextarea:', $remarksTextarea );
            console.log( 'Number of textareas found:', $remarksTextarea.length );

            $button.prop( 'disabled', true ).text( 'Signing...' );
            $messageArea.text( '' ).hide(); // Clear previous messages

            const pageId = $button.data( 'page-id' );
            const revId = $button.data( 'rev-id' );
            const signingTargetType = $button.data('target-type');
            const signingTargetValue = $button.data('target-value');
            
            // Add console.log for $button to verify it's a valid jQuery object
            console.log('$button object:', $button); 

            let remarks = '';
            if ( $remarksTextarea.length > 0 ) {
                remarks = $remarksTextarea.val() || ''; 
            }

            console.log( 'Page ID:', pageId, 'Rev ID:', revId, 'Target Type:', signingTargetType, 'Target Value:', signingTargetValue, 'Remarks:', remarks);

            if (!signingTargetType || !signingTargetValue) {
                $messageArea.text( 'Error: Signing target (group or user) not found. Cannot sign.' ).css( 'color', 'red' ).show();
                $button.prop( 'disabled', false ).text( 'Sign This Page' );
                return;
            }
            
            const apiPayload = {
                action: 'digitalsignature',
                pageid: pageId,
                revid: revId,
                format: 'json'
            };

            if (signingTargetType === 'group') {
                apiPayload.group = signingTargetValue;
            } else if (signingTargetType === 'user') {
                apiPayload.user = signingTargetValue;
            }

            if ( remarks.trim() !== '' ) { 
                apiPayload.remarks = remarks;
            }

            console.log('API Payload being sent:', apiPayload); // Log the final payload

            new mw.Api().post( apiPayload ).done( function ( data ) {
                console.log('API Success Response:', data); // Log success response
                if ( data.digitalsignature && data.digitalsignature.result === 'success' ) {
                    $messageArea.text( 'Page successfully signed!' ).css( 'color', 'green' ).show();
                    setTimeout( function() {
                        console.log("Reloading page after successful signature...");
                        location.reload();
                    }, 1000 );
                } else if ( data.error ) {
                    $messageArea.text( 'Error: ' + data.error.info ).css( 'color', 'red' ).show();
                } else {
                    $messageArea.text( 'An unknown error occurred.' ).css( 'color', 'red' ).show();
                }
                $button.prop( 'disabled', false ).text( 'Sign This Page' ); // Re-enable in case of non-fatal error
            } ).fail( function ( jqXHR, textStatus, errorThrown ) {
                console.error('API Fail Response:', jqXHR, textStatus, errorThrown); // Log error response
                let errorMessage = 'API Error: ' + textStatus;
                if ( jqXHR.responseJSON && jqXHR.responseJSON.error && jqXHR.responseJSON.error.info ) {
                    errorMessage = 'Error: ' + jqXHR.responseJSON.error.info;
                }
                $messageArea.text( errorMessage ).css( 'color', 'red' ).show();
                $button.prop( 'disabled', false ).text( 'Sign This Page' );
            } );
        } );
    } );
}( jQuery, mediaWiki ) );
