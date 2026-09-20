    </main>


    <footer class="app-footer">

        <span>
            © <?= date('Y') ?> Underground Apparel
        </span>

        <div class="app-footer-actions">

            <a
                href="/profile/"
                class="app-footer-link"
            >
                My Account
            </a>

            <span>
                UA POS
            </span>

        </div>

    </footer>


</div>



<!-- =========================================================
     GLOBAL CONFIRMATION MODAL
========================================================= -->

<div
    class="system-confirm-modal"
    id="systemConfirmModal"
    hidden
>


    <div
        class="system-confirm-backdrop"
        id="systemConfirmBackdrop"
    ></div>


    <div
        class="system-confirm-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="systemConfirmTitle"
        aria-describedby="systemConfirmMessage"
    >


        <button
            type="button"
            class="system-confirm-close"
            id="systemConfirmClose"
            aria-label="Close confirmation"
        >

            <span class="material-symbols-rounded">
                close
            </span>

        </button>


        <div class="system-confirm-icon">

            <span
                class="material-symbols-rounded"
                id="systemConfirmIcon"
            >
                warning
            </span>

        </div>


        <div class="system-confirm-content">

            <div class="system-confirm-eyebrow">
                CONFIRM ACTION
            </div>

            <h3 id="systemConfirmTitle">
                Are you sure?
            </h3>

            <p id="systemConfirmMessage">
                Please confirm before continuing.
            </p>

        </div>


        <div class="system-confirm-actions">

            <button
                type="button"
                class="system-confirm-cancel"
                id="systemConfirmCancel"
            >
                Cancel
            </button>


            <button
                type="button"
                class="system-confirm-submit"
                id="systemConfirmSubmit"
            >

                <span id="systemConfirmSubmitText">
                    Confirm
                </span>

                <span class="material-symbols-rounded">
                    arrow_forward
                </span>

            </button>

        </div>


    </div>


</div>



<script>

document.addEventListener(
    'DOMContentLoaded',
    () => {


        const modal =
            document.getElementById(
                'systemConfirmModal'
            );


        if (!modal) {
            return;
        }


        const backdrop =
            document.getElementById(
                'systemConfirmBackdrop'
            );


        const closeButton =
            document.getElementById(
                'systemConfirmClose'
            );


        const cancelButton =
            document.getElementById(
                'systemConfirmCancel'
            );


        const confirmButton =
            document.getElementById(
                'systemConfirmSubmit'
            );


        const confirmText =
            document.getElementById(
                'systemConfirmSubmitText'
            );


        const title =
            document.getElementById(
                'systemConfirmTitle'
            );


        const message =
            document.getElementById(
                'systemConfirmMessage'
            );


        const icon =
            document.getElementById(
                'systemConfirmIcon'
            );


        let pendingUrl =
            '';


        let previousFocus =
            null;


        function openConfirmation(
            trigger
        ) {

            pendingUrl =
                trigger.getAttribute(
                    'href'
                )
                ||
                trigger.dataset.confirmUrl
                ||
                '';


            previousFocus =
                document.activeElement;


            title.textContent =
                trigger.dataset.confirmTitle
                ||
                'Are you sure?';


            message.textContent =
                trigger.dataset.confirmMessage
                ||
                'Please confirm before continuing.';


            confirmText.textContent =
                trigger.dataset.confirmLabel
                ||
                'Confirm';


            icon.textContent =
                trigger.dataset.confirmIcon
                ||
                'warning';


            modal.hidden =
                false;


            document.body.classList.add(
                'system-confirm-open'
            );


            window.setTimeout(
                () => {

                    cancelButton.focus();

                },
                30
            );

        }


        function closeConfirmation() {

            modal.hidden =
                true;


            pendingUrl =
                '';


            document.body.classList.remove(
                'system-confirm-open'
            );


            if (
                previousFocus
                &&
                typeof previousFocus.focus
                === 'function'
            ) {

                previousFocus.focus();

            }

        }


        document.addEventListener(
            'click',
            event => {


                const trigger =
                    event.target.closest(
                        '[data-confirm]'
                    );


                if (!trigger) {
                    return;
                }


                event.preventDefault();


                openConfirmation(
                    trigger
                );

            }
        );


        confirmButton.addEventListener(
            'click',
            () => {


                if (
                    pendingUrl !== ''
                ) {

                    window.location.href =
                        pendingUrl;

                    return;
                }


                closeConfirmation();

            }
        );


        cancelButton.addEventListener(
            'click',
            closeConfirmation
        );


        closeButton.addEventListener(
            'click',
            closeConfirmation
        );


        backdrop.addEventListener(
            'click',
            closeConfirmation
        );


        document.addEventListener(
            'keydown',
            event => {


                if (
                    event.key === 'Escape'
                    &&
                    !modal.hidden
                ) {

                    event.preventDefault();

                    closeConfirmation();

                }

            }
        );


    }
);

</script>


</body>

</html>
