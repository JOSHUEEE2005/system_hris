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
const contractModalPanel = contractModal?.querySelector('.contract-modal-panel');
const personalContractsModal = document.getElementById('personal-contracts-modal');
const personalContractsTitle = document.getElementById('personal-contracts-title');
const personalContractsDescription = document.getElementById('personal-contracts-description');
const personalContractsList = document.getElementById('personal-contracts-list');
const contractDetailsModal = document.getElementById('contract-details-modal');
const contractDetailsTitle = document.getElementById('contract-details-title');
const contractDetailsDescription = document.getElementById('contract-details-description');
const contractDetailsRecipientSlot = document.getElementById('contract-details-recipient-slot');
const contractDetailsActionsSlot = document.getElementById('contract-details-actions-slot');
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
const contractAllChoice = document.getElementById('contract-all-choice');
const contractDepartmentSelect = contractDepartmentChoice?.querySelector('select');
const contractFolderSelect = document.getElementById('contract-folder');
const contractRecipientCheckboxes = [...document.querySelectorAll('input[name="recipient_ids[]"]')];
const contractRecipientSearch = document.getElementById('contract-recipient-search');
const contractRecipientOptions = [...document.querySelectorAll('[data-contract-recipient-option]')];
const contractResponseModeInputs = [...document.querySelectorAll('input[name="response_mode"]')];
const noContractRecipientsMatch = document.getElementById('contract-recipient-search-empty');
let contractEditorRange = null;
let personalContractsTrigger = null;
let contractDetailsTrigger = null;
let contractDetailsMovedNodes = [];

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

const fitConvertedPdfPages = () => {
    if (!(contractEditor instanceof HTMLElement)) return;

    const editorStyle = window.getComputedStyle(contractEditor);
    const horizontalPadding = Number.parseFloat(editorStyle.paddingLeft) + Number.parseFloat(editorStyle.paddingRight);
    const availableWidth = contractEditor.clientWidth - horizontalPadding;
    if (availableWidth <= 0) return;

    contractEditor.querySelectorAll('.converted-pdf-page').forEach((page) => {
        if (!(page instanceof HTMLElement)) return;
        const pageWidth = Number.parseFloat(page.style.width);
        if (!Number.isFinite(pageWidth) || pageWidth <= 0 || !page.style.width.trim().endsWith('pt')) return;

        page.style.zoom = String(Math.min(1, availableWidth / (pageWidth * 96 / 72)));
    });
};

const updateContractRecipientMode = () => {
    if (!(contractDepartmentChoice instanceof HTMLElement) || !(contractIndividualChoice instanceof HTMLElement)) {
        return;
    }

    const mode = contractModeInputs.find((input) => input.checked)?.value || 'department';
    contractDepartmentChoice.hidden = mode !== 'department';
    contractIndividualChoice.hidden = mode !== 'individual';
    if (contractAllChoice instanceof HTMLElement) contractAllChoice.hidden = mode !== 'all';

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
    const hasConvertedPdfPages = contractEditor instanceof HTMLElement
        && contractEditor.querySelector('.converted-pdf-page') !== null;
    contractModalPanel?.classList.toggle('contract-modal-panel-wide', hasConvertedPdfPages);
    contractModal.hidden = false;
    document.body.style.overflow = 'hidden';
    window.requestAnimationFrame(fitConvertedPdfPages);
    contractTitleInput?.focus();
};

const closeContractModal = () => {
    if (!(contractModal instanceof HTMLElement)) return;
    contractModal.hidden = true;
    document.body.style.overflow = '';
    contractOpenButton?.focus();
};

const restoreContractDetailsNodes = () => {
    [...contractDetailsMovedNodes].reverse().forEach(({ element, placeholder }) => {
        if (placeholder.parentNode) placeholder.replaceWith(element);
    });
    contractDetailsMovedNodes = [];
};

const closeContractDetails = (restoreFocus = true) => {
    if (!(contractDetailsModal instanceof HTMLElement) || contractDetailsModal.hidden) return;
    restoreContractDetailsNodes();
    contractDetailsModal.hidden = true;
    selectedPersonalContractUser = '';
    contractCards.forEach((card) => {
        card.querySelectorAll('[data-recipient-status-user]').forEach((detail) => {
            detail.hidden = false;
        });
    });
    if (!(contractModal instanceof HTMLElement) || contractModal.hidden) {
        document.body.style.overflow = '';
    }
    if (restoreFocus) contractDetailsTrigger?.focus();
    contractDetailsTrigger = null;
};

const closePersonalContracts = (restoreFocus = true) => {
    if (!(personalContractsModal instanceof HTMLElement) || personalContractsModal.hidden) return;
    personalContractsModal.hidden = true;
    if (!(contractModal instanceof HTMLElement) || contractModal.hidden) {
        document.body.style.overflow = '';
    }
    if (restoreFocus) personalContractsTrigger?.focus();
    personalContractsTrigger = null;
};

const openPersonalContracts = (trigger) => {
    if (
        !(personalContractsModal instanceof HTMLElement)
        || !(personalContractsList instanceof HTMLElement)
    ) return;

    const userId = trigger.dataset.personalFolderFilter || '';
    if (!userId) return;

    const name = trigger.querySelector('.contract-personal-folder-name')?.textContent?.trim() || 'Recipient';
    const matchingCards = contractCards.filter((card) => (
        (card.dataset.recipientUserIds || '').split(',').includes(userId)
    ));

    if (personalContractsTitle instanceof HTMLElement) {
        personalContractsTitle.textContent = `Contracts sent to ${name}`;
    }
    if (personalContractsDescription instanceof HTMLElement) {
        personalContractsDescription.textContent = 'See each contract, whether a response is required, and its current HR approval status.';
    }
    personalContractsList.replaceChildren();

    if (matchingCards.length === 0) {
        const emptyMessage = document.createElement('p');
        emptyMessage.className = 'contract-recipient-contract-empty';
        emptyMessage.textContent = 'No contracts have been sent to this recipient yet.';
        personalContractsList.append(emptyMessage);
    }

    matchingCards.forEach((card) => {
        const recipientStatus = [...card.querySelectorAll('[data-recipient-status-user]')]
            .find((detail) => detail.dataset.recipientStatusUser === userId);
        if (!(recipientStatus instanceof HTMLElement)) return;

        const title = card.querySelector('.contract-title-row h3')?.textContent?.trim() || 'Untitled contract';
        const sentDate = card.querySelector('.contract-card-meta span')?.textContent?.trim() || '';
        const responseRequired = card.dataset.contractResponseMode === 'requires_return';
        const recipientStatusLabel = recipientStatus.querySelector('span')?.textContent?.trim() || 'Action needed';
        const row = document.createElement('article');
        row.className = 'contract-recipient-contract-row';

        const header = document.createElement('div');
        header.className = 'contract-recipient-contract-header';
        const contractTitle = document.createElement('h3');
        contractTitle.textContent = title;
        const date = document.createElement('span');
        date.className = 'contract-recipient-contract-date';
        date.textContent = sentDate;
        header.append(contractTitle, date);

        const mode = document.createElement('p');
        mode.className = `contract-recipient-contract-mode ${responseRequired ? 'response-required' : 'final-copy'}`;
        mode.textContent = responseRequired
            ? 'Return requested · HR approval required'
            : 'Final copy · no response needed';

        const status = document.createElement('p');
        status.className = 'contract-recipient-contract-status';
        const isAwaitingApproval = responseRequired && recipientStatusLabel === 'Submitted for review';
        const needsRevision = recipientStatusLabel === 'Revision requested';
        const awaitingEmployee = responseRequired && (
            recipientStatusLabel === 'Action needed'
            || recipientStatusLabel === 'Return needed'
            || recipientStatusLabel === 'Not opened yet'
            || recipientStatusLabel.startsWith('Opened')
        );
        const isCompleted = recipientStatusLabel === 'Approved'
            || (!responseRequired && recipientStatusLabel.startsWith('Opened'));
        status.textContent = isAwaitingApproval
            ? 'Sent for approval · awaiting HR review'
            : needsRevision
                ? 'Revision requested · see HR feedback'
                : `Status: ${recipientStatusLabel}`;
        status.classList.add(
            isAwaitingApproval || needsRevision
                ? 'hr-review'
                : awaitingEmployee
                    ? 'employee-action'
                    : isCompleted
                        ? 'complete'
                        : 'neutral',
        );

        const openDetails = document.createElement('button');
        openDetails.type = 'button';
        openDetails.className = 'contract-action-secondary';
        openDetails.textContent = responseRequired ? 'Review contract' : 'View contract';
        openDetails.addEventListener('click', () => {
            const detailsTrigger = card.querySelector('[data-contract-details-open]');
            closePersonalContracts(false);
            if (detailsTrigger instanceof HTMLElement) {
                openContractDetails(detailsTrigger, trigger, userId);
            }
        });

        row.append(header, mode, status, openDetails);
        personalContractsList.append(row);
    });

    personalContractsTrigger = trigger;
    personalContractsModal.hidden = false;
    document.body.style.overflow = 'hidden';
    personalContractsModal.querySelector('[data-close-personal-contracts]')?.focus();
};

const openContractDetails = (trigger, focusTrigger = trigger, recipientUserId = '') => {
    if (
        !(contractDetailsModal instanceof HTMLElement)
        || !(contractDetailsRecipientSlot instanceof HTMLElement)
        || !(contractDetailsActionsSlot instanceof HTMLElement)
    ) return;

    closeContractDetails(false);
    selectedPersonalContractUser = recipientUserId;
    const card = trigger.closest('[data-contract-card]');
    if (!(card instanceof HTMLElement)) return;
    card.querySelectorAll('[data-recipient-status-user]').forEach((detail) => {
        detail.hidden = Boolean(recipientUserId) && detail.dataset.recipientStatusUser !== recipientUserId;
    });

    const title = card.querySelector('.contract-title-row h3')?.textContent?.trim() || 'Contract';
    const recipientDetails = card.querySelector('[data-contract-recipient-status-list]');
    const contractActions = card.querySelector('[data-contract-card-actions]');
    const moveIntoModal = (element, slot) => {
        if (!(element instanceof HTMLElement) || !element.parentNode) return;
        const placeholder = document.createComment('');
        element.parentNode.insertBefore(placeholder, element);
        slot.append(element);
        contractDetailsMovedNodes.push({ element, placeholder });
    };

    if (contractDetailsTitle instanceof HTMLElement) contractDetailsTitle.textContent = title;
    if (contractDetailsDescription instanceof HTMLElement) {
        const selectedFolder = personalContractFolderFilters
            .find((button) => button.dataset.personalFolderFilter === selectedPersonalContractUser);
        const selectedFolderName = selectedFolder?.querySelector('.contract-personal-folder-name')?.textContent?.trim();
        contractDetailsDescription.textContent = selectedPersonalContractUser && selectedFolderName
            ? `Reviewing ${selectedFolderName}'s copy and submission.`
            : 'Review each recipient’s status and manage this contract.';
    }

    moveIntoModal(recipientDetails, contractDetailsRecipientSlot);
    moveIntoModal(contractActions, contractDetailsActionsSlot);
    contractDetailsTrigger = focusTrigger;
    contractDetailsModal.hidden = false;
    document.body.style.overflow = 'hidden';
    contractDetailsModal.querySelector('[data-close-contract-details]')?.focus();
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
    contractResponseModeInputs.forEach((input) => { input.checked = input.value === 'as_is'; });
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
        contractResponseModeInputs.forEach((input) => { input.checked = input.value === button.dataset.responseMode; });
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
if (contractEditor instanceof HTMLElement) {
    if ('ResizeObserver' in window) {
        new ResizeObserver(fitConvertedPdfPages).observe(contractEditor);
    } else {
        window.addEventListener('resize', fitConvertedPdfPages);
    }
}
contractDocumentModeInputs.forEach((input) => input.addEventListener('change', updateContractDocumentMode));

contractOpenButton?.addEventListener('click', setContractCreateMode);
document.querySelectorAll('[data-contract-details-open]').forEach((button) => {
    button.addEventListener('click', () => openContractDetails(button));
});
contractEditButtons.forEach((button) => {
    button.addEventListener('click', () => {
        closeContractDetails(false);
        setContractEditMode(button);
    });
});
contractDetailsModal?.querySelectorAll('[data-close-contract-details]').forEach((button) => {
    button.addEventListener('click', () => closeContractDetails());
});
personalContractsModal?.querySelectorAll('[data-close-personal-contracts]').forEach((button) => {
    button.addEventListener('click', () => closePersonalContracts());
});
contractModal?.querySelectorAll('[data-close-contract-modal]').forEach((button) => {
    button.addEventListener('click', closeContractModal);
});
document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (personalContractsModal instanceof HTMLElement && !personalContractsModal.hidden) {
        closePersonalContracts();
    } else if (contractDetailsModal instanceof HTMLElement && !contractDetailsModal.hidden) {
        closeContractDetails();
    } else if (contractModal instanceof HTMLElement && !contractModal.hidden) {
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
const contractGrid = document.getElementById('contract-grid');
const contractShowAllButton = document.querySelector('[data-contract-show-all]');
const contractViewHeading = document.querySelector('[data-contract-view-heading]');
let selectedContractFolder = 'all';
let selectedPersonalContractUser = '';
let showAllContracts = false;

const filterContractCards = () => {
    const query = contractSearch instanceof HTMLInputElement ? contractSearch.value.trim().toLowerCase() : '';
    const hasActiveFilter = Boolean(query || selectedContractFolder !== 'all' || selectedPersonalContractUser);
    const recentLimit = Number(contractGrid?.dataset.contractLimit || 0);
    const currentUserId = contractGrid?.dataset.currentUserId || '';
    const isCompactRecent = recentLimit > 0
        && contractCards.length > recentLimit
        && !showAllContracts
        && !hasActiveFilter;
    let visibleCount = 0;

    contractGrid?.classList.toggle('contract-grid-compact', isCompactRecent);

    contractCards.forEach((card) => {
        const matchesSearch = card.dataset.search.includes(query);
        const matchesFolder = selectedContractFolder === 'all'
            || (selectedContractFolder === 'created-by-me' && card.dataset.contractCreatedBy === currentUserId)
            || (selectedContractFolder === 'unfiled' && !card.dataset.folderId)
            || card.dataset.folderId === selectedContractFolder;
        const recipientUserIds = (card.dataset.recipientUserIds || '').split(',');
        const matchesPersonalFolder = !selectedPersonalContractUser
            || recipientUserIds.includes(selectedPersonalContractUser);
        const isBeyondRecentLimit = recentLimit > 0
            && !showAllContracts
            && !hasActiveFilter
            && Number(card.dataset.contractIndex) >= recentLimit;
        card.hidden = !matchesSearch || !matchesFolder || !matchesPersonalFolder || isBeyondRecentLimit;
        card.querySelectorAll('[data-recipient-status-user]').forEach((detail) => {
            detail.hidden = Boolean(selectedPersonalContractUser)
                && detail.dataset.recipientStatusUser !== selectedPersonalContractUser;
        });
        if (!card.hidden) visibleCount++;
    });

    if (noContractCardsMatch instanceof HTMLElement) {
        noContractCardsMatch.hidden = contractCards.length === 0 || visibleCount !== 0;
    }

    if (contractViewHeading instanceof HTMLElement) {
        contractViewHeading.textContent = selectedContractFolder === 'created-by-me'
            ? 'Contracts created by you'
            : 'Recently sent';
    }

    if (contractShowAllButton instanceof HTMLButtonElement && recentLimit > 0) {
        contractShowAllButton.hidden = contractCards.length <= recentLimit || hasActiveFilter;
        contractShowAllButton.textContent = showAllContracts ? `Show recent ${recentLimit}` : `View all ${contractCards.length} contracts`;
        contractShowAllButton.setAttribute('aria-expanded', String(showAllContracts));
    }
};

contractShowAllButton?.addEventListener('click', () => {
    showAllContracts = !showAllContracts;
    filterContractCards();
});

personalContractFolderFilters.forEach((button) => {
    button.addEventListener('click', () => {
        if (button.hasAttribute('data-personal-contracts-open')) {
            openPersonalContracts(button);
            return;
        }

        selectedPersonalContractUser = button.dataset.personalFolderFilter || '';
        selectedContractFolder = 'all';
        contractFolderFilters.forEach((filter) => {
            filter.setAttribute('aria-pressed', String(filter.dataset.contractFolderFilter === 'all'));
        });
        filterContractCards();
    });
});

contractSearch?.addEventListener('input', filterContractCards);
contractFolderFilters.forEach((button) => {
    button.addEventListener('click', () => {
        selectedContractFolder = button.dataset.contractFolderFilter || 'all';
        selectedPersonalContractUser = '';
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
