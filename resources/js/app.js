import './bootstrap';

const themeToggle = document.querySelector('.theme-toggle');

if (themeToggle instanceof HTMLButtonElement) {
    const updateThemeToggle = () => {
        const isDark = document.documentElement.dataset.theme === 'dark';
        const action = isDark ? 'light' : 'dark';

        themeToggle.setAttribute('aria-label', `Switch to ${action} mode`);
        themeToggle.setAttribute('title', `Switch to ${action} mode`);
        themeToggle.setAttribute('aria-pressed', String(isDark));
    };

    updateThemeToggle();

    themeToggle.addEventListener('click', () => {
        const nextTheme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';

        document.documentElement.dataset.theme = nextTheme;
        localStorage.setItem('gpcci-theme', nextTheme);
        updateThemeToggle();
    });
}

const contractModal = document.getElementById('contract-modal');
const contractOpenButton = document.getElementById('open-contract-modal');
const contractForm = document.querySelector('#contract-modal form.contract-form');
const contractDeleteForm = document.getElementById('contract-delete-form');
const contractDeleteButton = document.getElementById('contract-delete-button');
const contractTitleInput = document.getElementById('contract-title');
const contractFileInput = document.getElementById('contract-file');
const contractFileLabel = document.getElementById('contract-file-label');
const contractFileHelp = document.getElementById('contract-file-help');
const contractCurrentFile = document.getElementById('contract-current-file');
const contractCurrentFilename = document.getElementById('contract-current-filename');
const contractSubmitButton = document.getElementById('contract-submit-button');
const contractMethodInput = document.getElementById('contract-method');
const contractRecordId = document.getElementById('contract-record-id');
const contractModalTitle = document.getElementById('contract-modal-title');
const contractModalDescription = document.getElementById('contract-modal-description');
const contractEditButtons = [...document.querySelectorAll('[data-contract-edit]')];
const contractModeInputs = [...document.querySelectorAll('input[name="recipient_type"]')];
const contractDepartmentChoice = document.getElementById('contract-department-choice');
const contractIndividualChoice = document.getElementById('contract-individual-choice');
const contractDepartmentSelect = contractDepartmentChoice?.querySelector('select');
const contractRecipientCheckboxes = [...document.querySelectorAll('input[name="recipient_ids[]"]')];
const contractRecipientSearch = document.getElementById('contract-recipient-search');
const contractRecipientOptions = [...document.querySelectorAll('[data-contract-recipient-option]')];
const noContractRecipientsMatch = document.getElementById('contract-recipient-search-empty');

const updateContractRecipientMode = () => {
    if (!(contractDepartmentChoice instanceof HTMLElement) || !(contractIndividualChoice instanceof HTMLElement)) {
        return;
    }

    const mode = contractModeInputs.find((input) => input.checked)?.value || 'department';
    contractDepartmentChoice.hidden = mode !== 'department';
    contractIndividualChoice.hidden = mode !== 'individual';

    if (contractDepartmentSelect instanceof HTMLSelectElement) {
        contractDepartmentSelect.disabled = mode !== 'department';
    }

    contractIndividualChoice.querySelectorAll('input').forEach((input) => {
        input.disabled = mode !== 'individual';
    });
};

const filterContractRecipients = () => {
    if (!(contractRecipientSearch instanceof HTMLInputElement) || !(noContractRecipientsMatch instanceof HTMLElement)) {
        return;
    }

    const query = contractRecipientSearch.value.trim().toLowerCase();
    let visibleCount = 0;
    contractRecipientOptions.forEach((option) => {
        const matches = option.dataset.search.includes(query);
        option.hidden = !matches;
        if (matches) visibleCount++;
    });
    noContractRecipientsMatch.hidden = visibleCount !== 0;
};

const showContractModal = () => {
    if (!(contractModal instanceof HTMLElement)) return;
    contractModal.hidden = false;
    document.body.style.overflow = 'hidden';
    contractTitleInput?.focus();
};

const closeContractModal = () => {
    if (!(contractModal instanceof HTMLElement)) return;
    contractModal.hidden = true;
    document.body.style.overflow = '';
    contractOpenButton?.focus();
};

const setContractCreateMode = () => {
    if (!(contractForm instanceof HTMLFormElement)) return;

    contractForm.action = contractForm.dataset.storeUrl;
    contractForm.reset();
    if (contractTitleInput instanceof HTMLInputElement) contractTitleInput.value = '';
    if (contractFileInput instanceof HTMLInputElement) {
        contractFileInput.value = '';
        contractFileInput.required = true;
    }
    if (contractFileLabel) contractFileLabel.innerHTML = 'Contract file <span>*</span>';
    if (contractFileHelp) {
        contractFileHelp.textContent = 'Any file type is accepted, up to 35 MB. PDF and common image files preview here; other types can be downloaded.';
    }
    if (contractCurrentFile instanceof HTMLElement) contractCurrentFile.hidden = true;
    if (contractMethodInput instanceof HTMLInputElement) contractMethodInput.disabled = true;
    if (contractRecordId instanceof HTMLInputElement) contractRecordId.value = '';
    if (contractDepartmentSelect instanceof HTMLSelectElement) {
        contractDepartmentSelect.value = '';
    }
    contractModeInputs.forEach((input) => { input.checked = input.value === 'department'; });
    contractRecipientCheckboxes.forEach((input) => { input.checked = false; });
    if (contractRecipientSearch instanceof HTMLInputElement) contractRecipientSearch.value = '';
    if (contractModalTitle) contractModalTitle.textContent = 'Send a new contract';
    if (contractModalDescription) contractModalDescription.textContent = 'Upload a file and choose who should receive it.';
    if (contractSubmitButton instanceof HTMLButtonElement) contractSubmitButton.textContent = 'Upload and send';
    if (contractDeleteButton instanceof HTMLButtonElement) contractDeleteButton.hidden = true;
    if (contractDeleteForm instanceof HTMLFormElement) contractDeleteForm.action = contractForm.dataset.storeUrl;
    updateContractRecipientMode();
    filterContractRecipients();
    showContractModal();
};

const setContractEditMode = (button, preserveValues = false) => {
    if (!(contractForm instanceof HTMLFormElement)) return;

    contractForm.action = button.dataset.updateUrl;
    if (contractMethodInput instanceof HTMLInputElement) contractMethodInput.disabled = false;
    if (contractRecordId instanceof HTMLInputElement) contractRecordId.value = button.dataset.contractId;
    if (contractDeleteForm instanceof HTMLFormElement) contractDeleteForm.action = button.dataset.deleteUrl;
    if (contractDeleteButton instanceof HTMLButtonElement) contractDeleteButton.hidden = false;

    if (!preserveValues) {
        if (contractTitleInput instanceof HTMLInputElement) contractTitleInput.value = button.dataset.title;
        if (contractDepartmentSelect instanceof HTMLSelectElement) {
            contractDepartmentSelect.value = button.dataset.departmentId;
        }
        contractModeInputs.forEach((input) => { input.checked = input.value === button.dataset.recipientType; });
        const selectedIds = new Set(button.dataset.recipientIds.split(',').filter(Boolean));
        contractRecipientCheckboxes.forEach((input) => { input.checked = selectedIds.has(input.value); });
    }

    if (contractFileInput instanceof HTMLInputElement) {
        contractFileInput.value = '';
        contractFileInput.required = false;
    }
    if (contractFileLabel) contractFileLabel.textContent = 'Replace file (optional)';
    if (contractFileHelp) {
        contractFileHelp.textContent = 'Leave this empty to keep the current file. Replacing it resets all recipients to Pending.';
    }
    if (contractCurrentFile instanceof HTMLElement) contractCurrentFile.hidden = false;
    if (contractCurrentFilename) contractCurrentFilename.textContent = button.dataset.filename;
    if (contractRecipientSearch instanceof HTMLInputElement) contractRecipientSearch.value = '';
    if (contractModalTitle) contractModalTitle.textContent = 'Edit contract';
    if (contractModalDescription) contractModalDescription.textContent = 'Update the title, file, or recipients for this contract.';
    if (contractSubmitButton instanceof HTMLButtonElement) contractSubmitButton.textContent = 'Save changes';
    updateContractRecipientMode();
    filterContractRecipients();
    showContractModal();
};

if (contractDepartmentChoice instanceof HTMLElement && contractIndividualChoice instanceof HTMLElement) {
    updateContractRecipientMode();
}

contractOpenButton?.addEventListener('click', setContractCreateMode);
contractEditButtons.forEach((button) => {
    button.addEventListener('click', () => setContractEditMode(button));
});
contractModal?.querySelectorAll('[data-close-contract-modal]').forEach((button) => {
    button.addEventListener('click', closeContractModal);
});
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && contractModal instanceof HTMLElement && !contractModal.hidden) {
        closeContractModal();
    }
});
contractModeInputs.forEach((input) => input.addEventListener('change', updateContractRecipientMode));
contractRecipientSearch?.addEventListener('input', filterContractRecipients);

if (contractModal instanceof HTMLElement && !contractModal.hidden) {
    const recordId = contractRecordId instanceof HTMLInputElement ? contractRecordId.value : '';
    const editButton = contractEditButtons.find((button) => button.dataset.contractId === recordId);
    if (editButton) {
        setContractEditMode(editButton, true);
    } else {
        updateContractRecipientMode();
        filterContractRecipients();
        document.body.style.overflow = 'hidden';
        contractTitleInput?.focus();
    }
}

contractForm?.addEventListener('submit', (event) => {
    if (!event.currentTarget.checkValidity()) return;
    if (contractSubmitButton instanceof HTMLButtonElement) {
        contractSubmitButton.disabled = true;
        contractSubmitButton.textContent = 'Saving…';
    }
});

contractDeleteForm?.addEventListener('submit', (event) => {
    const title = contractTitleInput instanceof HTMLInputElement ? contractTitleInput.value : 'this contract';
    if (!window.confirm(`Delete "${title}" and remove its file and recipient access?`)) {
        event.preventDefault();
    }
});

const contractSearch = document.getElementById('contract-search');
const contractCards = [...document.querySelectorAll('[data-contract-card]')];
const noContractCardsMatch = document.getElementById('contract-search-empty');

if (contractSearch instanceof HTMLInputElement && noContractCardsMatch instanceof HTMLElement) {
    contractSearch.addEventListener('input', () => {
        const query = contractSearch.value.trim().toLowerCase();
        let visibleCount = 0;

        contractCards.forEach((card) => {
            const matches = card.dataset.search.includes(query);
            card.hidden = !matches;
            if (matches) visibleCount++;
        });

        noContractCardsMatch.hidden = visibleCount !== 0;
    });
}

const headerPopoverToggles = [...document.querySelectorAll('[data-header-popover-toggle]')];
const headerPopovers = headerPopoverToggles
    .map((button) => ({
        button,
        panel: document.getElementById(button.getAttribute('aria-controls')),
        container: button.closest('[data-header-popover]'),
    }))
    .filter((popover) => popover.panel instanceof HTMLElement && popover.container instanceof HTMLElement);

const closeHeaderPopovers = (returnFocus = false) => {
    const openPopover = headerPopovers.find(({ panel }) => !panel.hidden);
    headerPopovers.forEach(({ button, panel }) => {
        panel.hidden = true;
        button.setAttribute('aria-expanded', 'false');
    });
    if (returnFocus) openPopover?.button.focus();
};

headerPopovers.forEach(({ button, panel }) => {
    button.addEventListener('click', () => {
        const shouldOpen = panel.hidden;
        closeHeaderPopovers();
        if (shouldOpen) {
            panel.hidden = false;
            button.setAttribute('aria-expanded', 'true');
        }
    });
});

document.addEventListener('click', (event) => {
    if (event.target instanceof Element && !event.target.closest('[data-header-popover]')) {
        closeHeaderPopovers();
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeHeaderPopovers(true);
});
