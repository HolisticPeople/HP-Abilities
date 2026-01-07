/**
 * Yoast SEO Custom Assessment for Google Merchant Center (GMC) Compliance.
 * Flags forbidden keywords like "cure", "treat", etc. in real-time.
 */
(function() {
    'use strict';

    // Wait for YoastSEO to be ready
    window.addEventListener('YoastSEO:ready', function() {
        
        /**
         * The GMC Compliance Plugin for Yoast
         */
        class GMCCompliancePlugin {
            constructor() {
                this.forbiddenKeywords = window.hpGmcComplianceData.forbiddenKeywords || {};
                
                // Register the plugin with Yoast
                YoastSEO.app.registerPlugin('GMCCompliancePlugin', { status: 'ready' });
                
                // Register the assessment
                YoastSEO.app.registerAssessment(
                    'gmc-compliance',
                    this.assessment.bind(this),
                    'GMCCompliancePlugin'
                );
            }

            /**
             * The assessment logic
             * @param {Object} paper The Yoast paper object containing content
             */
            assessment(paper) {
                const text = (paper.getText() + ' ' + (paper.getDescription() || '')).toLowerCase();
                const found = [];

                for (const kw in this.forbiddenKeywords) {
                    const regex = new RegExp('\\b' + kw + '\\b', 'i');
                    if (regex.test(text)) {
                        found.push(kw);
                    }
                }

                if (found.length === 0) {
                    return {
                        score: 9, // Green
                        text: 'GMC Compliance: No forbidden keywords found in description.',
                        identifier: 'gmc-compliance'
                    };
                }

                return {
                    score: 3, // Red/Orange
                    text: 'GMC Compliance: Forbidden keywords found (' + found.join(', ') + '). This may lead to product disapproval in Google Shopping.',
                    identifier: 'gmc-compliance'
                };
            }
        }

        // Initialize
        new GMCCompliancePlugin();
    });
})();

