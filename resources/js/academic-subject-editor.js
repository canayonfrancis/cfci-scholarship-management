import academicGrades, { academicNumber, academicDecimal } from './academic-grades';

const gradeFields = ['prelim', 'midterm', 'semi_final', 'final', 'average'];
const specialMarks = ['INC', 'INCOMPLETE', 'DRP', 'DROPPED'];

// UI state composes the existing calculator. Only committed rows enter its summary.
export default function academicSubjectEditor(rows, emptyRow, backendErrors = []) {
    return {
        rows, emptyRow,
        subject(row) { return academicGrades(this.rows, this.emptyRow).subject(row); },
        get summary() { return academicGrades(this.rows, this.emptyRow).summary; },
        rowKeys: rows.map((_, index) => `subject-${index}`),
        nextKey: rows.length,
        rowErrors: rows.map((_, index) => backendErrors[index] ?? {}),
        draft: { ...emptyRow },
        initialDraft: '',
        editIndex: null,
        modalErrors: {},
        confirmation: null,
        pendingRemoval: null,
        notice: '',
        returnFocus: null,
        confirmationReturnFocus: null,
        previousOverflow: null,
        parentForm: null,
        submitGuard: null,

        init() {
            this.parentForm = this.$el.closest('form');
            this.submitGuard = (event) => {
                if (this.$refs.subjectDialog.open || this.$refs.confirmationDialog.open) {
                    event.preventDefault();
                    this.notice = 'Finish or cancel the open subject dialog before saving the academic record.';
                }
            };
            this.parentForm?.addEventListener('submit', this.submitGuard);
        },
        destroy() {
            this.parentForm?.removeEventListener('submit', this.submitGuard);
            if (this.previousOverflow !== null) document.body.style.overflow = this.previousOverflow;
        },
        lockScroll() {
            if (this.previousOverflow === null) this.previousOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
        },
        unlockScroll() {
            if (this.$refs.subjectDialog.open || this.$refs.confirmationDialog.open) return;
            if (this.previousOverflow !== null) document.body.style.overflow = this.previousOverflow;
            this.previousOverflow = null;
        },
        focusBack(element) {
            this.$nextTick(() => {
                if (element?.isConnected && !element.disabled) element.focus();
                else this.$refs.addSubject.focus();
            });
        },
        openSubject(index = null, trigger = null) {
            if (index === null && this.rows.length >= 60) return;
            this.editIndex = index;
            this.draft = { ...(index === null ? this.emptyRow : this.rows[index]) };
            this.initialDraft = JSON.stringify(this.draft);
            this.modalErrors = {};
            this.notice = '';
            this.returnFocus = trigger ?? document.activeElement;
            this.lockScroll();
            this.$refs.subjectDialog.showModal();
            this.$nextTick(() => this.$refs.subjectName.focus());
        },
        get dirty() { return JSON.stringify(this.draft) !== this.initialDraft; },
        requestClose() {
            if (this.dirty) this.openConfirmation('discard');
            else this.closeSubject();
        },
        closeSubject() {
            this.$refs.subjectDialog.close();
            this.draft = { ...this.emptyRow };
            this.modalErrors = {};
            this.unlockScroll();
            this.focusBack(this.returnFocus);
        },
        validateSubject() {
            const errors = {};
            const value = (key) => String(this.draft[key] ?? '').trim();
            const length = (key) => [...value(key)].length;
            const fail = (key, message) => { errors[key] = [message]; };
            if (!value('subject_name')) fail('subject_name', 'Enter the subject name.');
            else if (length('subject_name') > 255) fail('subject_name', 'Subject name must not exceed 255 characters.');
            if (length('subject_code') > 60) fail('subject_code', 'Subject code must not exceed 60 characters.');
            else if (value('subject_code') && this.rows.some((row, index) => index !== this.editIndex
                && String(row.subject_code ?? '').trim().toUpperCase() === value('subject_code').toUpperCase())) {
                fail('subject_code', 'This subject code is already in the table. Edit that subject or use the correct distinct code.');
            }
            if (value('units') && academicNumber(value('units')) === null) fail('units', 'Units must be a nonnegative decimal of at most 30 characters. Leave unavailable units blank.');
            for (const key of gradeFields) {
                if (value(key) && (length(key) > 30 || (!specialMarks.includes(value(key).toUpperCase()) && academicNumber(value(key), true) === null))) {
                    fail(key, 'Enter a percentage decimal from 0 to 100 or INC/INCOMPLETE/DRP/DROPPED. Leave missing grades blank.');
                }
            }
            if (!['regular', 'inc', 'dropped', 'unverified'].includes(value('reported_status'))) fail('reported_status', 'Choose a supported reported subject status.');
            if (length('remarks') > 2000) fail('remarks', 'Remarks must not exceed 2,000 characters.');
            if (this.editIndex === null && this.rows.length >= 60) fail('_row', 'The form allows up to 60 subject rows.');
            return errors;
        },
        saveSubject() {
            this.modalErrors = this.validateSubject();
            if (Object.keys(this.modalErrors).length) {
                this.$nextTick(() => {
                    const field = Object.keys(this.modalErrors)[0];
                    (this.$refs.subjectDialog.querySelector(`[data-subject-field="${field}"]`) ?? this.$refs.modalErrors).focus();
                });
                return;
            }
            const row = { ...this.draft };
            if (this.editIndex === null) {
                this.rows.push(row);
                this.rowKeys.push(`subject-${this.nextKey++}`);
                this.rowErrors.push({});
                this.notice = 'Subject added to this form. Save or verify the academic record to keep the change.';
            } else {
                this.rows.splice(this.editIndex, 1, row);
                this.rowErrors.splice(this.editIndex, 1, {});
                this.notice = 'Subject changes applied to this form. Save or verify the academic record to keep them.';
            }
            this.closeSubject();
        },
        modalEnter(event) {
            if (event.isComposing || !event.target.matches('input')) return;
            event.preventDefault();
            this.saveSubject();
        },
        openConfirmation(kind, index = null, trigger = null) {
            this.confirmation = kind;
            this.pendingRemoval = index;
            this.confirmationReturnFocus = trigger ?? document.activeElement;
            this.lockScroll();
            this.$refs.confirmationDialog.showModal();
            this.$nextTick(() => this.$refs.cancelConfirmation.focus());
        },
        cancelConfirmation() {
            this.$refs.confirmationDialog.close();
            this.confirmation = null;
            this.pendingRemoval = null;
            this.unlockScroll();
            this.focusBack(this.confirmationReturnFocus);
        },
        confirmAction() {
            const kind = this.confirmation;
            const index = this.pendingRemoval;
            const trigger = this.confirmationReturnFocus;
            this.$refs.confirmationDialog.close();
            this.confirmation = null;
            this.pendingRemoval = null;
            if (kind === 'discard') {
                this.closeSubject();
                return;
            }
            if (kind === 'remove' && index !== null) {
                this.rows.splice(index, 1);
                this.rowKeys.splice(index, 1);
                this.rowErrors.splice(index, 1);
                this.notice = 'Subject removed from this form. Previously verified revisions are unchanged.';
            }
            this.unlockScroll();
            this.focusBack(trigger);
        },
        trapFocus(event) {
            const dialog = event.currentTarget;
            const focusable = [...dialog.querySelectorAll('button, input:not([type="hidden"]), select, textarea, [tabindex="0"]')]
                .filter(element => !element.disabled && element.getClientRects().length);
            if (!focusable.length) { event.preventDefault(); dialog.focus(); return; }
            const first = focusable[0], last = focusable[focusable.length - 1];
            if (event.shiftKey && (document.activeElement === first || !focusable.includes(document.activeElement))) {
                event.preventDefault(); last.focus();
            } else if (!event.shiftKey && (document.activeElement === last || !focusable.includes(document.activeElement))) {
                event.preventDefault(); first.focus();
            }
        },
        fieldErrors(field) {
            return [...(this.modalErrors[field] ?? []), ...(this.editIndex === null ? [] : this.rowErrors[this.editIndex]?.[field] ?? [])];
        },
        errorsFor(index) { return Object.values(this.rowErrors[index] ?? {}).flat(); },
        get previousErrors() { return this.editIndex === null ? [] : this.errorsFor(this.editIndex); },
        get localErrors() { return Object.values(this.modalErrors).flat(); },
        unroundedAverage(row) {
            const value = this.subject(row).average;
            return value === null ? 'Not complete' : academicDecimal(value);
        },
        resultClass(row) {
            const result = this.subject(row).result;
            return result === 'Passed' ? 'is-green' : result === 'Failed' ? 'academic-result-failed' : 'is-amber';
        },
    };
}
