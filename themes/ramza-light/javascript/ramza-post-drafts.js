(function (window, $) {
    'use strict';

    var databaseName = 'ramza_post_drafts';
    var storeName = 'drafts';
    var config = null;
    var form = null;
    var activeDraft = null;
    var fallbackPrefix = 'ramza_post_draft:';

    function nameSelector(name) {
        var escaped = window.CSS && typeof window.CSS.escape === 'function'
            ? window.CSS.escape(name)
            : name.replace(/([\\"\[\]])/g, '\\$1');
        return '[name="' + escaped + '"]';
    }

    function draftKey() {
        return [
            'user', config.userId,
            'page', fieldValue('page_id'),
            'group', fieldValue('group_id'),
            'event', fieldValue('event_id'),
            'recipient', fieldValue('recipient_id')
        ].join(':');
    }

    function fieldValue(name) {
        var element = form ? form.querySelector('[name="' + name + '"]') : null;
        return element ? String(element.value || '0') : '0';
    }

    function openDatabase() {
        return new Promise(function (resolve, reject) {
            if (!window.indexedDB) {
                reject(new Error('IndexedDB is unavailable.'));
                return;
            }
            var request = window.indexedDB.open(databaseName, 1);
            request.onupgradeneeded = function () {
                if (!request.result.objectStoreNames.contains(storeName)) {
                    request.result.createObjectStore(storeName, { keyPath: 'key' });
                }
            };
            request.onsuccess = function () { resolve(request.result); };
            request.onerror = function () { reject(request.error || new Error('Draft storage could not be opened.')); };
        });
    }

    function databaseAction(mode, value) {
        return openDatabase().then(function (database) {
            return new Promise(function (resolve, reject) {
                var transaction = database.transaction(storeName, mode === 'get' ? 'readonly' : 'readwrite');
                var store = transaction.objectStore(storeName);
                var request = mode === 'get' ? store.get(value) : (mode === 'put' ? store.put(value) : store.delete(value));
                request.onsuccess = function () { resolve(request.result || null); };
                request.onerror = function () { reject(request.error || new Error('Draft storage failed.')); };
                transaction.oncomplete = function () { database.close(); };
                transaction.onabort = transaction.onerror = function () { database.close(); };
            });
        });
    }

    function fallbackGet(key) {
        try {
            var raw = window.localStorage.getItem(fallbackPrefix + key);
            return raw ? JSON.parse(raw) : null;
        } catch (error) {
            return null;
        }
    }

    function fallbackPut(draft) {
        var textOnly = Object.assign({}, draft, { files: [], attachmentsUnavailable: draft.files.length > 0 });
        window.localStorage.setItem(fallbackPrefix + draft.key, JSON.stringify(textOnly));
        return textOnly;
    }

    function fallbackDelete(key) {
        try { window.localStorage.removeItem(fallbackPrefix + key); } catch (error) { /* Storage is optional. */ }
    }

    function getDraft() {
        var key = draftKey();
        return databaseAction('get', key).catch(function () { return fallbackGet(key); }).then(function (draft) {
            if (draft && Date.now() - Number(draft.updatedAt || 0) > 30 * 24 * 60 * 60 * 1000) {
                return removeDraft().then(function () { return null; });
            }
            return draft;
        });
    }

    function removeDraft() {
        var key = draftKey();
        activeDraft = null;
        fallbackDelete(key);
        return databaseAction('delete', key).catch(function () { return null; });
    }

    function serializableControls() {
        var excluded = ['hash_id', 'filename', 'musiccount'];
        return Array.prototype.filter.call(form.querySelectorAll('input[name], textarea[name], select[name]'), function (element) {
            var type = String(element.type || '').toLowerCase();
            return type !== 'file' && type !== 'button' && type !== 'submit' && type !== 'password' && excluded.indexOf(element.name) === -1;
        });
    }

    function captureDraft() {
        var controls = serializableControls();
        var fields = [];
        controls.forEach(function (element) {
            var type = String(element.type || '').toLowerCase();
            if ((type === 'checkbox' || type === 'radio') && !element.checked) return;
            var matching = Array.prototype.filter.call(form.querySelectorAll(nameSelector(element.name)), function (candidate) {
                return String(candidate.type || '').toLowerCase() !== 'file';
            });
            fields.push({
                name: element.name,
                index: matching.indexOf(element),
                type: type,
                value: String(element.value || '')
            });
        });

        var files = [];
        Array.prototype.forEach.call(form.querySelectorAll('input[type="file"]'), function (input, inputIndex) {
            Array.prototype.forEach.call(input.files || [], function (file) {
                files.push({
                    inputId: input.id || '',
                    inputName: input.name || '',
                    inputIndex: inputIndex,
                    name: file.name,
                    type: file.type,
                    lastModified: file.lastModified,
                    blob: file
                });
            });
        });

        return {
            key: draftKey(),
            version: 1,
            userId: String(config.userId),
            updatedAt: Date.now(),
            fields: fields,
            files: files
        };
    }

    function hasDraftContent(draft) {
        if (draft.files.length) return true;
        return draft.fields.some(function (field) {
            if (['page_id', 'group_id', 'event_id', 'recipient_id', 'postPrivacy'].indexOf(field.name) !== -1) return false;
            return String(field.value || '').trim() !== '';
        });
    }

    function resetComposer() {
        if (!form) return;
        form.reset();
        $('#image-holder').empty();
        $('#postSticker, #post_color_input').val('');
        $('#post_video').removeAttr('src');
        $('.feelings-value').empty();
        $('.extracted_url').remove();
        $('.video-form, .emo-form, .music-form, .map-form, .file-form, .photo-form, .album-form, .feeling-type, .poll-form, .gif-form').hide();
        if (typeof window.Wo_ResetAnswers === 'function') window.Wo_ResetAnswers();
    }

    function notify(message) {
        if ($ && $.fn && $.fn.snackbar) {
            $('body').snackbar({ message: message });
        }
    }

    function saveDiscarded() {
        if (!form) return Promise.resolve(false);
        var draft = captureDraft();
        if (!hasDraftContent(draft)) {
            return removeDraft().then(function () { resetComposer(); return false; });
        }
        return databaseAction('put', draft).then(function () {
            activeDraft = draft;
            fallbackDelete(draft.key);
            resetComposer();
            notify(config.savedText);
            return true;
        }).catch(function () {
            try {
                activeDraft = fallbackPut(draft);
                resetComposer();
                notify(draft.files.length ? config.savedWithoutFilesText : config.savedText);
                return true;
            } catch (error) {
                notify(config.failedText);
                return false;
            }
        });
    }

    function ensureControl(field) {
        var controls = Array.prototype.filter.call(form.querySelectorAll(nameSelector(field.name)), function (candidate) {
            return String(candidate.type || '').toLowerCase() !== 'file';
        });
        while (field.name === 'answer[]' && controls.length <= field.index && typeof window.Wo_AddAnswer === 'function') {
            window.Wo_AddAnswer();
            controls = Array.prototype.filter.call(form.querySelectorAll('[name="answer[]"]'), function (candidate) {
                return String(candidate.type || '').toLowerCase() !== 'file';
            });
        }
        return controls[field.index] || null;
    }

    function restoreFiles(files) {
        if (!window.DataTransfer || !files || !files.length) return;
        var groups = {};
        files.forEach(function (entry) {
            var groupKey = entry.inputId || ('index:' + entry.inputIndex);
            if (!groups[groupKey]) groups[groupKey] = [];
            groups[groupKey].push(entry);
        });
        Object.keys(groups).forEach(function (groupKey) {
            var entries = groups[groupKey];
            var input = entries[0].inputId ? document.getElementById(entries[0].inputId) : form.querySelectorAll('input[type="file"]')[entries[0].inputIndex];
            if (!input) return;
            var transfer = new DataTransfer();
            entries.forEach(function (entry) {
                transfer.items.add(new File([entry.blob], entry.name, { type: entry.type || '', lastModified: entry.lastModified || Date.now() }));
            });
            input.files = transfer.files;
            $(input).trigger('change');
        });
    }

    function restoreDraft() {
        if (!activeDraft || !form) return;
        resetComposer();
        activeDraft.fields.forEach(function (field) {
            var control = ensureControl(field);
            if (!control) return;
            if (field.type === 'checkbox' || field.type === 'radio') control.checked = true;
            else control.value = field.value;
        });
        restoreFiles(activeDraft.files || []);
        var sticker = form.querySelector('#postSticker');
        if (sticker && sticker.value) {
            var image = document.createElement('img');
            image.src = sticker.value;
            image.className = 'thumb-image';
            $('#image-holder').empty().append(image).show();
            $('.gif-photo-form').show();
        }
        $('.postText').trigger('input').trigger('keyup').focus();
        $('#ramza-draft-notice').attr('hidden', true);
        notify(config.restoredText);
    }

    function deleteDraft() {
        removeDraft().then(function () {
            $('#ramza-draft-notice').attr('hidden', true);
            notify(config.deletedText);
        });
    }

    function refreshNotice() {
        return getDraft().then(function (draft) {
            activeDraft = draft;
            var notice = $('#ramza-draft-notice');
            if (!draft) {
                notice.attr('hidden', true);
                return;
            }
            var count = (draft.files || []).length;
            notice.find('[data-draft-time]').text(new Date(draft.updatedAt).toLocaleString());
            notice.find('[data-draft-files]').text(count ? config.fileText.replace('{count}', count) : '');
            notice.removeAttr('hidden');
        });
    }

    function clearAfterPublish() {
        return removeDraft().then(function () { $('#ramza-draft-notice').attr('hidden', true); });
    }

    function init(options) {
        config = options;
        form = document.getElementById('publisher-box-focus');
        if (!form || !config || !config.userId) return;
        $(document).on('show.bs.modal', '#tagPostBox', refreshNotice);
        $(document).on('click', '#ramza-restore-draft', restoreDraft);
        $(document).on('click', '#ramza-delete-draft', deleteDraft);
        refreshNotice();
    }

    window.RamzaPostDrafts = {
        init: init,
        saveDiscarded: saveDiscarded,
        clearAfterPublish: clearAfterPublish,
        refreshNotice: refreshNotice
    };
})(window, window.jQuery);
