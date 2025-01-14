(function(window) {
    /**
     * Hide an element or a list of them.
     * @param {Element|NodeList} options 
     */
    function hideElements(options) {
        if (options instanceof NodeList) {
            for (const option of options)
                hideElements(option);
        } else {
            // Hide row container if exists
            var container = options.closest('tr') != null ? options.closest('tr') : options;

            container.classList.toggle('hidden');
        }
    }

    /**
     * Init mobbex config tabs.
     * @param {Array.<string>} tabNames 
     */
    function initConfigTabs(tabNames) {
        tabNames.forEach(tabName => {
            // Get tab
            var tab = document.querySelector('.mbbx-tab-' + tabName);

            // Create expand button
            tab.setAttribute('data-content', '+');

            // Hide all tab options
            hideElements(document.querySelectorAll('.mbbx-into-' + tabName));

            tab.onclick = function () {
                var dataContent = tab.getAttribute('data-content') == '+';

                // Toggle expand button
                if (dataContent) {
                    tab.setAttribute('data-content', '-');
                } else {
                    tab.setAttribute('data-content', '+');
                }

                // Display alert in advanced configuration tab
                if (tabName == 'advanced' && dataContent) {
                    window.alert('¡Cuidado! Estas opciones son avanzadas, modifiquelas sólo si sabe exactamente lo que hace.')
                }

                // Show options again
                hideElements(document.querySelectorAll('.mbbx-into-' + tabName));
            }
        });
    }

    /**
     * Add dynamic to dni fields.
     */
    function addDynamicToDniFields() {
        // Get dni fields
        var ownDni    = document.getElementById('woocommerce_mobbex_own_dni');
        var customDni = document.getElementById('woocommerce_mobbex_custom_dni');

        // If own dni option is active, hide custom dni option
        if (ownDni.checked) hideElements(customDni);
        ownDni.onchange = function () { hideElements(customDni); }
    }

    window.addEventListener('load', function () {
        initConfigTabs(['appearance', 'advanced', 'orders', 'subscription']);
        addDynamicToDniFields();
    });
}) (window)