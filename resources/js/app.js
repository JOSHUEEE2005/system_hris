import './bootstrap';

const adminSidebar = document.querySelector('.sa-side');

if (adminSidebar instanceof HTMLElement) {
    const expandedKey = 'sa-nav-expanded';
    const isMobileNavigation = () => window.matchMedia('(max-width: 800px)').matches;
    let navigating = false;

    adminSidebar.addEventListener('pointerleave', () => {
        if (navigating) return;

        sessionStorage.removeItem(expandedKey);
        document.documentElement.classList.remove('sa-nav-expanded');
    });

    adminSidebar.querySelectorAll('a[href]').forEach((link) => {
        link.addEventListener('click', (event) => {
            if (
                isMobileNavigation()
                || link.target === '_blank'
                || link.hasAttribute('download')
                || event.button !== 0
                || event.metaKey
                || event.ctrlKey
                || event.shiftKey
                || event.altKey
            ) {
                return;
            }

            navigating = true;
            sessionStorage.setItem(expandedKey, '1');
        });
    });
}

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
const contractSourceInput = document.getElementById('contract-source');
const contractDocumentModeInputs = [...document.querySelectorAll('input[name="contract_document_mode"]')];
const contractUploadPanel = document.getElementById('contract-upload-panel');
const contractEditorPanel = document.getElementById('contract-editor-panel');
const contractEditor = document.getElementById('contract-editor');
const contractContentHtml = document.getElementById('contract-content-html');
const contractEditorBlock = document.getElementById('contract-editor-block');
const contractTableRows = document.getElementById('contract-table-rows');
const contractTableColumns = document.getElementById('contract-table-columns');
const contractPlaceholder = document.getElementById('contract-placeholder');
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
const contractFolderSelect = document.getElementById('contract-folder');
const contractRecipientCheckboxes = [...document.querySelectorAll('input[name="recipient_ids[]"]')];
const contractRecipientSearch = document.getElementById('contract-recipient-search');
const contractRecipientOptions = [...document.querySelectorAll('[data-contract-recipient-option]')];
const noContractRecipientsMatch = document.getElementById('contract-recipient-search-empty');
let contractEditorRange = null;

const rememberContractEditorRange = () => {
    const selection = window.getSelection();
    if (
        selection?.rangeCount
        && contractEditor instanceof HTMLElement
        && contractEditor.contains(selection.anchorNode)
    ) {
        contractEditorRange = selection.getRangeAt(0).cloneRange();
    }
};

const restoreContractEditorRange = () => {
    if (!(contractEditor instanceof HTMLElement)) return;
    contractEditor.focus();
    if (!contractEditorRange) return;
    const selection = window.getSelection();
    selection?.removeAllRanges();
    selection?.addRange(contractEditorRange);
};

const updateContractDocumentMode = () => {
    const mode = contractDocumentModeInputs.find((input) => input.checked)?.value || 'upload';
    const currentSource = contractForm?.dataset.currentSource || 'new';
    if (contractSourceInput instanceof HTMLInputElement) contractSourceInput.value = mode;
    if (contractUploadPanel instanceof HTMLElement) contractUploadPanel.hidden = mode !== 'upload';
    if (contractEditorPanel instanceof HTMLElement) contractEditorPanel.hidden = mode !== 'editor';
    if (contractFileInput instanceof HTMLInputElement) {
        contractFileInput.disabled = mode !== 'upload';
        contractFileInput.required = mode === 'upload' && currentSource !== 'upload';
    }
    if (contractContentHtml instanceof HTMLTextAreaElement) {
        contractContentHtml.disabled = mode !== 'editor';
    }
    if (contractFileLabel) {
        contractFileLabel.innerHTML = mode === 'upload' && currentSource !== 'upload'
            ? `Contract file <span>*</span>`
            : currentSource === 'upload' ? 'Replace file (optional)' : 'Contract file';
    }
    if (contractSubmitButton instanceof HTMLButtonElement && currentSource === 'new') {
        contractSubmitButton.textContent = mode === 'editor' ? 'Create and send' : 'Upload and send';
        if (contractModalDescription) {
            contractModalDescription.textContent = mode === 'editor'
                ? 'Write a contract, add recipient fields, and choose who should receive it.'
                : 'Upload a file and choose who should receive it.';
        }
    }
};

const syncContractEditor = () => {
    if (contractEditor instanceof HTMLElement && contractContentHtml instanceof HTMLTextAreaElement) {
        contractContentHtml.value = contractEditor.innerHTML;
    }
};

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
    contractForm.dataset.currentSource = 'new';
    contractEditorRange = null;
    contractDocumentModeInputs.forEach((input) => { input.checked = input.value === 'upload'; });
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
    if (contractEditor instanceof HTMLElement) contractEditor.innerHTML = '';
    if (contractContentHtml instanceof HTMLTextAreaElement) contractContentHtml.value = '';
    if (contractMethodInput instanceof HTMLInputElement) contractMethodInput.disabled = true;
    if (contractRecordId instanceof HTMLInputElement) contractRecordId.value = '';
    if (contractDepartmentSelect instanceof HTMLSelectElement) {
        contractDepartmentSelect.value = '';
    }
    if (contractFolderSelect instanceof HTMLSelectElement) {
        contractFolderSelect.value = '';
    }
    contractModeInputs.forEach((input) => { input.checked = input.value === 'department'; });
    contractRecipientCheckboxes.forEach((input) => { input.checked = false; });
    if (contractRecipientSearch instanceof HTMLInputElement) contractRecipientSearch.value = '';
    if (contractModalTitle) contractModalTitle.textContent = 'Send a new contract';
    if (contractModalDescription) contractModalDescription.textContent = 'Upload a file and choose who should receive it.';
    if (contractSubmitButton instanceof HTMLButtonElement) contractSubmitButton.textContent = 'Upload and send';
    if (contractDeleteButton instanceof HTMLButtonElement) contractDeleteButton.hidden = true;
    if (contractDeleteForm instanceof HTMLFormElement) contractDeleteForm.action = contractForm.dataset.storeUrl;
    updateContractDocumentMode();
    updateContractRecipientMode();
    filterContractRecipients();
    showContractModal();
};

const setContractEditMode = (button, preserveValues = false) => {
    if (!(contractForm instanceof HTMLFormElement)) return;

    contractForm.action = button.dataset.updateUrl;
    contractForm.dataset.currentSource = button.dataset.sourceType || 'upload';
    if (contractMethodInput instanceof HTMLInputElement) contractMethodInput.disabled = false;
    if (contractRecordId instanceof HTMLInputElement) contractRecordId.value = button.dataset.contractId;
    if (contractDeleteForm instanceof HTMLFormElement) contractDeleteForm.action = button.dataset.deleteUrl;
    if (contractDeleteButton instanceof HTMLButtonElement) contractDeleteButton.hidden = false;

    if (!preserveValues) {
        contractEditorRange = null;
        if (contractTitleInput instanceof HTMLInputElement) contractTitleInput.value = button.dataset.title;
        if (contractDepartmentSelect instanceof HTMLSelectElement) {
            contractDepartmentSelect.value = button.dataset.departmentId;
        }
        if (contractFolderSelect instanceof HTMLSelectElement) {
            contractFolderSelect.value = button.dataset.folderId || '';
        }
        contractModeInputs.forEach((input) => { input.checked = input.value === button.dataset.recipientType; });
        const selectedIds = new Set(button.dataset.recipientIds.split(',').filter(Boolean));
        contractRecipientCheckboxes.forEach((input) => { input.checked = selectedIds.has(input.value); });
        const source = button.dataset.sourceType || 'upload';
        contractDocumentModeInputs.forEach((input) => { input.checked = input.value === source; });
        if (contractEditor instanceof HTMLElement) contractEditor.innerHTML = button.dataset.contentHtml || '';
        if (contractContentHtml instanceof HTMLTextAreaElement) contractContentHtml.value = button.dataset.contentHtml || '';
    }

    if (contractFileInput instanceof HTMLInputElement) {
        contractFileInput.value = '';
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
    updateContractDocumentMode();
    updateContractRecipientMode();
    filterContractRecipients();
    showContractModal();
};

if (contractDepartmentChoice instanceof HTMLElement && contractIndividualChoice instanceof HTMLElement) {
    updateContractDocumentMode();
    updateContractRecipientMode();
}
contractDocumentModeInputs.forEach((input) => input.addEventListener('change', updateContractDocumentMode));

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

contractModal?.querySelectorAll('[data-contract-command]').forEach((button) => {
    button.addEventListener('mousedown', (event) => event.preventDefault());
    button.addEventListener('click', () => {
        if (!(contractEditor instanceof HTMLElement)) return;
        contractEditor.focus();
        document.execCommand(button.dataset.contractCommand, false);
        syncContractEditor();
    });
});

contractEditorBlock?.addEventListener('focus', rememberContractEditorRange);
contractEditorBlock?.addEventListener('change', () => {
    if (!(contractEditor instanceof HTMLElement) || !(contractEditorBlock instanceof HTMLSelectElement)) return;
    restoreContractEditorRange();
    document.execCommand('formatBlock', false, `<${contractEditorBlock.value}>`);
    syncContractEditor();
});

contractPlaceholder?.addEventListener('change', () => {
    if (
        !(contractEditor instanceof HTMLElement)
        || !(contractPlaceholder instanceof HTMLSelectElement)
        || !contractPlaceholder.value
    ) return;
    restoreContractEditorRange();
    document.execCommand('insertText', false, contractPlaceholder.value);
    contractPlaceholder.value = '';
    rememberContractEditorRange();
    syncContractEditor();
});
contractEditor?.addEventListener('keyup', rememberContractEditorRange);
contractEditor?.addEventListener('mouseup', rememberContractEditorRange);
contractPlaceholder?.addEventListener('focus', rememberContractEditorRange);
contractTableRows?.addEventListener('focus', rememberContractEditorRange);
contractTableColumns?.addEventListener('focus', rememberContractEditorRange);

contractModal?.querySelectorAll('[data-contract-insert-table], [data-contract-insert-section]').forEach((button) => {
    button.addEventListener('mousedown', (event) => event.preventDefault());
    button.addEventListener('click', () => {
        if (!(contractEditor instanceof HTMLElement)) return;
        restoreContractEditorRange();

        if (button.hasAttribute('data-contract-insert-table')) {
            const readSize = (input) => {
                const parsed = Number.parseInt(input?.value || '', 10);
                return Number.isFinite(parsed) ? Math.min(10, Math.max(1, parsed)) : 3;
            };
            const rows = readSize(contractTableRows);
            const columns = readSize(contractTableColumns);
            const headerCells = Array.from({ length: columns }, (_, index) => `<th>Header ${index + 1}</th>`).join('');
            const bodyRows = Array.from({ length: rows - 1 }, () => (
                `<tr>${'<td><br></td>'.repeat(columns)}</tr>`
            )).join('');
            const table = `<table><thead><tr>${headerCells}</tr></thead><tbody>${bodyRows}</tbody></table><p><br></p>`;
            document.execCommand('insertHTML', false, table);
        } else {
            const sectionName = button.dataset.contractInsertSection;
            if (sectionName !== 'header' && sectionName !== 'footer') return;
            const existing = [...contractEditor.children].find((child) => child.tagName.toLowerCase() === sectionName);
            if (existing) {
                const paragraph = existing.querySelector('p') || existing;
                const range = document.createRange();
                range.selectNodeContents(paragraph);
                range.collapse(false);
                const selection = window.getSelection();
                selection?.removeAllRanges();
                selection?.addRange(range);
            } else {
                const section = document.createElement(sectionName);
                const paragraph = document.createElement('p');
                paragraph.append(document.createElement('br'));
                section.append(paragraph);
                if (sectionName === 'header') {
                    contractEditor.insertBefore(section, contractEditor.firstChild);
                } else {
                    contractEditor.append(section);
                }
                const range = document.createRange();
                range.selectNodeContents(paragraph);
                range.collapse(false);
                const selection = window.getSelection();
                selection?.removeAllRanges();
                selection?.addRange(range);
            }
        }

        rememberContractEditorRange();
        syncContractEditor();
    });
});
contractEditor?.addEventListener('input', syncContractEditor);

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
    const source = contractSourceInput instanceof HTMLInputElement ? contractSourceInput.value : 'upload';
    if (source === 'editor') {
        syncContractEditor();
        if (!(contractContentHtml instanceof HTMLTextAreaElement) || !contractContentHtml.value.replace(/<[^>]*>/g, '').trim()) {
            event.preventDefault();
            contractEditor?.focus();
            return;
        }
    }
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
const contractFolderFilters = [...document.querySelectorAll('[data-contract-folder-filter]')];
const personalContractFolderFilters = [...document.querySelectorAll('[data-personal-folder-filter]')];
let selectedContractFolder = 'all';
let selectedPersonalContractUser = '';

const filterContractCards = () => {
    const query = contractSearch instanceof HTMLInputElement ? contractSearch.value.trim().toLowerCase() : '';
    let visibleCount = 0;

    contractCards.forEach((card) => {
        const matchesSearch = card.dataset.search.includes(query);
        const matchesFolder = selectedContractFolder === 'all'
            || (selectedContractFolder === 'unfiled' && !card.dataset.folderId)
            || card.dataset.folderId === selectedContractFolder;
        const recipientUserIds = (card.dataset.recipientUserIds || '').split(',');
        const matchesPersonalFolder = !selectedPersonalContractUser
            || recipientUserIds.includes(selectedPersonalContractUser);
        card.hidden = !matchesSearch || !matchesFolder || !matchesPersonalFolder;
        if (!card.hidden) visibleCount++;
    });

    if (noContractCardsMatch instanceof HTMLElement) {
        noContractCardsMatch.hidden = contractCards.length === 0 || visibleCount !== 0;
    }
};

personalContractFolderFilters.forEach((button) => {
    button.addEventListener('click', () => {
        selectedPersonalContractUser = button.dataset.personalFolderFilter || '';
        selectedContractFolder = 'all';
        contractFolderFilters.forEach((filter) => {
            filter.setAttribute('aria-pressed', String(filter.dataset.contractFolderFilter === 'all'));
        });
        personalContractFolderFilters.forEach((folderFilter) => {
            folderFilter.setAttribute('aria-pressed', String(folderFilter === button));
        });
        filterContractCards();
    });
});

contractSearch?.addEventListener('input', filterContractCards);
contractFolderFilters.forEach((button) => {
    button.addEventListener('click', () => {
        selectedContractFolder = button.dataset.contractFolderFilter || 'all';
        selectedPersonalContractUser = '';
        personalContractFolderFilters.forEach((folderFilter) => {
            folderFilter.setAttribute('aria-pressed', String(folderFilter.dataset.personalFolderFilter === ''));
        });
        contractFolderFilters.forEach((filter) => {
            filter.setAttribute('aria-pressed', String(filter === button));
        });
        filterContractCards();
    });
});
filterContractCards();

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
