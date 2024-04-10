(function waitForRequireJS() {
    if (typeof require === "undefined") {
        // RequireJS is not available yet, check again in 100ms
        setTimeout(waitForRequireJS, 100);
    } else {

        require([
            'ko', // KnockoutJS
            'jquery',
            'Magento_Ui/js/modal/alert',
        ], function(ko, $, alert) {
            // Your KnockoutJS-dependent code here

            function showErrorModal(errorMessage) {
                alert({
                    title: ' <div style="background: linear-gradient(to right, #32CD32, #00BFFF, #FFD700); -webkit-background-clip: text; color: transparent;">🔑 Auto login for admin script 🚀</div>',
                    content: `
                    <p>
                        Did you do the initial setup with <pre>roll setup-autologin</pre> <b>After executing above command, refresh the page!</b> </br></br>Otherwise, try to log in manually. If the problem persists, turn off auto login in your .env.roll<pre>ROLL_ADMIN_AUTOLOGIN=0</pre>
                    </p>
                    <hr></br>
                    <p>
                        Found the following errors: <pre>${errorMessage}</pre>
                    </p>
                    <p>If the above message is something with CAPTCHA, this usually means that the setup has not been done.</p>
                    <p>Have you done that already? Then click on the OK button below and fill in the captcha yourself and login manually. The username and password are already prefilled. 😊</p>
                `,
                    actions: {
                        always: function(){}
                    }
                });

            }
            function isPathMatchAndClassExists() {
                const pathMatch = window.location.href.includes("backend") ||
                    window.location.href.includes("shopmanager");

                const classExists = document.body.classList.contains('page-layout-admin-login');

                return pathMatch && classExists;
            }

            if (isPathMatchAndClassExists()) {

                console.log("%c🌟 Auto login for admin script is active! 🍰", "background: linear-gradient(to right, #32CD32, #00BFFF, #FFD700); color: transparent; -webkit-background-clip: text; font-size: 16px;");
                console.log("Auto admin login is only used in a local environment\nand is injected through nginx for convenience and not changing the project root files. 😊\nTo disable, add ROLL_ADMIN_AUTOLOGIN=0 in your .env.roll config. 💡\n\n⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⢀⠤⠒⠒⠒⠒⠠⢄⡀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀\n⠀⠀⠀⠀⠀⠀⠀⠀⢀⡴⠞⠀⠀⠀⠀⠀⠀⠀⠘⣆⠀⠀⠀⠀⠀⠀⠀⠀⠀\n⠀⠀⠀⠀⠀⠀⠀⠀⢻⠄⢠⠔⠒⠒⠒⠒⠒⢢⡀⢸⡄⠀⠀⠀⠀⠀⠀⠀⠀\n⠀⠀⠀⠀⠀⠀⠀⠀⡼⠀⠇⠀⠀⠀⠀⠀⠀⠀⢳⢸⠂⠀⠀⠀⠀⠀⠀⠀⠀\n⠀⠀⠀⠀⠀⠀⠀⣀⣹⠞⠀⠀⠀⠀⠀⠀⠀⠀⣸⣼⠀⠀⠀⠀⠀⠀⠀⠀⠀\n⠀⠀⠀⠀⠀⠀⠀⠹⡀⠀⠀⠀⠀⠀⠀⠀⠀⠀⢻⡅⠀⠀⠀⠀⠀⠀⠀⠀⠀\n⠀⠀⠀⠀⠀⠀⠀⠀⠙⢲⠀⠀⠀⠀⠀⠀⠀⢠⡞⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀\n⠀⠀⠀⠀⠀⠀⠀⠀⠀⢸⠀⠀⠀⠀⠀⠀⢠⠋⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀\n⠀⠀⠀⠀⠀⠀⠀⠀⢠⣿⠀⠀⠀⠀⠀⠀⢸⡧⣀⡀⠀⠀⠀⠀⠀⠀⠀⠀⠀\n⠀⠀⠀⠀⢀⡠⠔⢚⡟⡏⠉⠙⡇⠀⠀⣠⠟⡇⠀⠉⠁⠀⠒⠠⠄⣀⠀⠀⠀\n⠀⣠⠔⠊⠁⠀⠀⢸⠀⡇⠀⢰⠃⣀⠜⠁⢰⠃⠀⠀⠀⠀⠀⠀⠀⠀⠈⠳⡀\n⡞⠀⠀⠀⠀⠀⠀⢸⣀⣇⣀⣸⣯⡁⠀⡠⡇⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⢹\n⡇⠀⠀⠀⠀⠀⠀⠈⢹⣰⠛⡆⠀⠈⠉⢰⠃⠀⠀⠀⠀⣴⠶⡀⠀⠀⠀⠀⢸\n⡇⠀⠀⠀⠀⠀⠀⠀⢸⣟⠀⡇⠀⠀⠀⢸⠀⠀⠀⠀⠀⢹⠀⢇⠀⢴⣦⠀⣏\n⠀⠀⠀⠀⠀⠀⠀⠀⠈⣿⣴⠃⠀⠀⠀⢸⡄⠀⠀⠀⠀⢈⠀⠈⣓⢻⡟⢱⡛");

                const usernameField = document.getElementById("username");
                const passwordField = document.getElementById("login");

                // Function to simulate detailed user interaction with an input field
                function simulateUserInteraction(element, value) {
                    // Ensure the element is not null
                    if (!element) return;

                    // Focus on the element to start the interaction
                    element.focus();

                    // Simulate a keydown event as if the user is starting to type
                    element.dispatchEvent(new KeyboardEvent('keydown', {bubbles: true}));

                    // Change the element's value
                    element.value = value;

                    // Dispatch input and change events to mimic the user typing and changing the value
                    element.dispatchEvent(new Event('input', { bubbles: true }));
                    element.dispatchEvent(new Event('change', { bubbles: true }));

                    // Simulate a keyup event as if the user has finished typing
                    element.dispatchEvent(new KeyboardEvent('keyup', {bubbles: true}));

                    // Blur the element to indicate the user has moved on
                    element.blur();
                }

                if (usernameField) {
                    simulateUserInteraction(usernameField, "localadmin");
                }
                if (passwordField) {
                    simulateUserInteraction(passwordField, "admin123");
                }

                // Attempt to trigger the KnockoutJS bound function directly
                function triggerKnockoutBoundFunction() {
                    var button = document.querySelector('.action-login.action-primary');
                    if (button) {
                        // Access Knockout's binding context
                        var bindingContext = ko.contextFor(button);

                        if (bindingContext && bindingContext.$data && typeof bindingContext.$data.value === 'function') {
                            bindingContext.$data.value('');
                        }

                        if (bindingContext && bindingContext.$data && typeof bindingContext.$data.doVerify === 'function') {
                            // Directly invoke the doVerify function
                            bindingContext.$data.doVerify();
                        } else {
                            // Fallback to simulating a click event if the direct approach doesn't work
                            button.click();
                        }
                    }
                }



                // Base32 decode function for converting secret to ArrayBuffer
                function base32Decode(encoded) {
                    const base32chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
                    let paddingChar = "=";
                    let bufferLength = encoded.length * 5 / 8;
                    let result = new ArrayBuffer(bufferLength);
                    let bytes = new Uint8Array(result);
                    let bufferIndex = 0;
                    let bits = 0;
                    let bitsRemaining = 0;
                    for (let i = 0; i < encoded.length; i++) {
                        let val = base32chars.indexOf(encoded[i].toUpperCase());
                        if (val < 0 && encoded[i] !== paddingChar) {
                            throw new Error("Invalid base32 character.");
                        }
                        bits = (bits << 5) | val;
                        bitsRemaining += 5;
                        if (bitsRemaining >= 8) {
                            bitsRemaining -= 8;
                            bytes[bufferIndex++] = (bits >> bitsRemaining) & 255;
                        }
                    }
                    return result;
                }

                // Convert time to byte array
                function timeToBytes(time) {
                    const buffer = new ArrayBuffer(8);
                    const view = new DataView(buffer);
                    view.setUint32(4, Math.floor(time / 1000 / 30), false); // Use big endian
                    return buffer;
                }

                // Generate TOTP
                async function generateTOTP(secretBase32) {
                    const secret = base32Decode(secretBase32);
                    const time = timeToBytes(Date.now());
                    const key = await window.crypto.subtle.importKey("raw", secret, { name: "HMAC", hash: "SHA-1" }, false, ["sign"]);
                    const signature = await window.crypto.subtle.sign("HMAC", key, time);
                    const hmac = new Uint8Array(signature);
                    const offset = hmac[hmac.length - 1] & 0xf;
                    const code = ((hmac[offset] & 0x7f) << 24) |
                        ((hmac[offset + 1] & 0xff) << 16) |
                        ((hmac[offset + 2] & 0xff) << 8) |
                        (hmac[offset + 3] & 0xff);
                    const otp = code % 1000000;
                    return otp.toString().padStart(6, "0");
                }

                // Function to attempt to fill the TOTP field, with retry up to 50 times
                let attempts = 0; // Counter for the number of attempts
                let checkFieldInterval; // Declare interval variable for broader scope

                function attemptFillTOTPField() {
                    const totpField = document.getElementById("tfa_code"); // Replace "tfa_code" with the actual ID

                    if (totpField) {
                        clearInterval(checkFieldInterval); // Found the field, stop checking
                        fillAndDispatchTOTP(); // Fill the TOTP field immediately

                        // // Schedule the next TOTP code refresh just after the current one expires
                        // setTimeout(() => {
                        //     // Click the login button again after filling TOTP
                        //     triggerKnockoutBoundFunction();
                        // }, 1000); // Adjust delay to ensure the TOTP code is processed

                    } else if (attempts >= 50) {
                        clearInterval(checkFieldInterval); // Stop trying after 50 attempts
                    }
                    attempts++;
                }

                // Function to apply the value when ready
                function applyTOTPWhenReady(totpValue) {

                    const totpField = document.getElementById("tfa_code");
                    if (!totpField) {
                        const observer = new MutationObserver((mutations, obs) => {
                            const totpField = document.getElementById("tfa_code");
                            if (totpField) {
                                // Access Knockout's binding context
                                var bindingContext = ko.contextFor(totpField);
                                if (bindingContext && bindingContext.$data && typeof bindingContext.$data.verifyCode === 'function') {
                                    bindingContext.$data.verifyCode(totpValue);
                                }

                                simulateUserInteraction(totpField, totpValue);
                                obs.disconnect(); // Stop observing once we've found and set the TOTP
                                triggerKnockoutBoundFunction();
                            }
                        });

                        observer.observe(document.body, {
                            childList: true,
                            subtree: true
                        });
                    } else {
                        // Access Knockout's binding context
                        var bindingContext = ko.contextFor(totpField);
                        if (bindingContext && bindingContext.$data && typeof bindingContext.$data.verifyCode === 'function') {
                            bindingContext.$data.verifyCode(totpValue);
                        }

                        simulateUserInteraction(totpField, totpValue);
                        triggerKnockoutBoundFunction();
                    }

                }

                // Calculates the milliseconds until the next TOTP period
                function calculateTimeUntilNextTOTP() {
                    const period = 30; // TOTP period in seconds
                    return (period - Math.floor(Date.now() / 1000) % period) * 1000;
                }

                // Fills the TOTP field and schedules the next refresh
                function fillAndDispatchTOTP() {
                    generateTOTP("LAJWZZTAI4KAHY7NBS6NOM3BDZK62IPDT3U5ARGXCJ4WVG7PJSG37FC4XODYWV2UNYMQG3LVMEUTIHO52FQGZU4Z462VNWYKPOM23M2YZNWAJW732RTNAVTQ2APUV64BPBJZKT7I4CAT62KLFEP5DZLWINPZ3JVOWJ6CPOAA77RSFK2PAG6YPS4VP55WYX5BPTX4Z7P6EZ4CY").then(totpValue => {
                        applyTOTPWhenReady(totpValue);
                        // Wait for the current TOTP to expire, then refresh it
                        setTimeout(fillAndDispatchTOTP, calculateTimeUntilNextTOTP());
                    });
                }


                // Start the process if were on the right page
                if (window.location.href.includes("tfa/google/auth")) {
                    checkFieldInterval = setInterval(attemptFillTOTPField, 500);
                } else {
                    function checkForErrorMessageAndAlert() {
                        // Zoek naar het element met de class "message message-error error"
                        var errorMessageElement = document.querySelector('.message.message-error.error');

                        // Controleer of het element bestaat
                        if (errorMessageElement) {
                            // Het element bestaat, geef een waarschuwing in de console of als een alert
                            var errMsg = errorMessageElement.textContent.trim();
                            console.log('Er is een foutmelding gevonden: ' + errMsg);
                            showErrorModal(errMsg);
                        } else {
                            // Het element bestaat niet, geef eventueel een andere melding of doe niets
                            triggerKnockoutBoundFunction();
                        }
                    }
                    setTimeout(() => { // Add a slight delay to ensure fields are filled
                        checkForErrorMessageAndAlert();
                    }, 200); // Adjust delay as necessary
                }

            }
        });
    }
})();