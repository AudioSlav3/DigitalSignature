( function ( $, mw ) {
    /**
     * Compares two strings word by word and returns HTML with differences highlighted.
     * Highlights words that are added, removed, or changed.
     * @param {string} originalText The first string (e.g., Wiki Title).
     * @param {string} changedText The second string (e.g., Git Title).
     * @returns {string} HTML string with highlighted differences.
     */
    function getHighlightedDiffHtml(originalText, changedText) {
        console.log("getHighlightedDiffHtml called with:", { originalText, changedText });

        if (!originalText && !changedText) return '<p>No content to compare.</p>';
        if (!originalText) {
            return `<p>${mw.msg('digitalsignature-diff-all-added')}: <span class="diff-added">${changedText}</span></p>`;
        }
        if (!changedText) {
            return `<p>${mw.msg('digitalsignature-diff-all-removed')}: <span class="diff-removed">${originalText}</span></p>`;
        }

        const words1 = originalText.split(/\s+/).filter(w => w.length > 0);
        const words2 = changedText.split(/\s+/).filter(w => w.length > 0);

        let html = [];
        let ptr1 = 0; // Pointer for words1
        let ptr2 = 0; // Pointer for words2

        while (ptr1 < words1.length || ptr2 < words2.length) {
            const word1 = words1[ptr1];
            const word2 = words2[ptr2];

            if (word1 && word2 && word1.toLowerCase() === word2.toLowerCase()) {
                html.push(word2);
                ptr1++;
                ptr2++;
            } else {
                let foundMatch1in2 = -1;
                let foundMatch2in1 = -1;

                for (let k = ptr2 + 1; k < words2.length && k < ptr2 + 5; k++) {
                    if (word1 && words1[ptr1] && words1[ptr1].toLowerCase() === words2[k].toLowerCase()) {
                        foundMatch1in2 = k;
                        break;
                    }
                }
                for (let k = ptr1 + 1; k < words1.length && k < ptr1 + 5; k++) {
                    if (word2 && words2[ptr2] && words2[ptr2].toLowerCase() === words1[k].toLowerCase()) {
                        foundMatch2in1 = k;
                        break;
                    }
                }

                if (foundMatch1in2 !== -1 && (foundMatch2in1 === -1 || (foundMatch1in2 - ptr2 <= foundMatch2in1 - ptr1))) {
                    for (let k = ptr2; k < foundMatch1in2; k++) {
                        if (words2[k]) html.push(`<span class="diff-added">${words2[k]}</span>`);
                    }
                    ptr2 = foundMatch1in2;
                } else if (foundMatch2in1 !== -1 && (foundMatch1in2 === -1 || (foundMatch2in1 - ptr1 < foundMatch1in2 - ptr2))) {
                    for (let k = ptr1; k < foundMatch2in1; k++) {
                        if (words1[k]) html.push(`<span class="diff-removed">${words1[k]}</span>`);
                    }
                    ptr1 = foundMatch2in1;
                } else {
                    if (word1) html.push(`<span class="diff-removed">${word1}</span>`);
                    if (word2) html.push(`<span class="diff-added">${word2}</span>`);
                    ptr1++;
                    ptr2++;
                }

                if ((ptr1 < words1.length || ptr2 < words2.length) && html.length > 0 && html[html.length - 1] !== ' ') {
                    html.push(' ');
                }
            }
        }

        const finalHtml = html.join('').trim();
        return finalHtml;
    }

    $( function () {
        console.log("DigitalSignature.js: Document ready. Initializing...");

        // Handle diff display on page load if applicable
        $( '.digital-signature-diff-area' ).each( function() {
            const $diffArea = $( this );
            const showChanges = $diffArea.data( 'show-changes' );
            const oldContent = $diffArea.data( 'old-content' );
            const newContent = $diffArea.data( 'new-content' );
            const $diffOutput = $diffArea.find( '.digital-signature-diff-output' );

            console.log("DigitalSignature.js: Diff area found. showChanges:", showChanges, "oldContent length:", oldContent ? oldContent.length : 0, "newContent length:", newContent ? newContent.length : 0);

            if ( showChanges ) {
                if ( oldContent || newContent ) {
                    const diffHtml = getHighlightedDiffHtml( oldContent, newContent );
                    if ( diffHtml.trim() === '' || ( !oldContent && !newContent ) ) {
                        $diffOutput.html( '<p>' + mw.msg('digitalsignature-no-changes-found') + '</p>' );
                    } else {
                        $diffOutput.html( diffHtml );
                    }
                } else {
                    $diffOutput.html( '<p>' + mw.msg('digitalsignature-no-content-for-diff') + '</p>' );
                }
            }
        } );
        
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

            const showChangesFlag = $button.data('show-changes-flag'); // Get the show_changes flag from the button

            let remarks = '';
            if ( $remarksTextarea.length > 0 ) {
                remarks = $remarksTextarea.val() || ''; 
            }

            console.log( 'Page ID:', pageId, 'Rev ID:', revId, 'Target Type:', signingTargetType, 'Target Value:', signingTargetValue, 'Remarks:', remarks, 'Show Changes Flag:', showChangesFlag );

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
