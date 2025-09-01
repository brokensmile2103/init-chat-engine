/* global jQuery, InitChatMgmt */
(function ($) {
    $(function () {
        function toggleBulkActions() {
            var checkedItems = $('.wp-list-table tbody input[type="checkbox"]:checked').length;
            if (checkedItems > 0) {
                $('.bulk-actions').show();
                $('#bulk-selected-count').text(checkedItems);
            } else {
                $('.bulk-actions').hide();
            }
        }

        $('#cb-select-all-1, #cb-select-all-2').on('change', function () {
            var isChecked = $(this).prop('checked');
            $('.wp-list-table tbody input[type="checkbox"]').prop('checked', isChecked);
            toggleBulkActions();
        });

        $('.wp-list-table').on('change', 'tbody input[type="checkbox"]', function () {
            var total = $('.wp-list-table tbody input[type="checkbox"]').length;
            var checked = $('.wp-list-table tbody input[type="checkbox"]:checked').length;
            $('#cb-select-all-1, #cb-select-all-2').prop('checked', total === checked);
            toggleBulkActions();
        });

        $('#bulk-action-form').on('submit', function (e) {
            var action = $('#bulk-action-selector').val();
            var selectedItems = $('.wp-list-table tbody input[type="checkbox"]:checked');

            if (!action || action === '-1') {
                e.preventDefault();
                alert(InitChatMgmt.i18n.please_select_action);
                return false;
            }

            if (selectedItems.length === 0) {
                e.preventDefault();
                alert(InitChatMgmt.i18n.please_select_item);
                return false;
            }

            var confirmMessage = '';
            if (action === 'bulk_delete') confirmMessage = InitChatMgmt.i18n.confirm_delete;
            if (action === 'bulk_ban') confirmMessage = InitChatMgmt.i18n.confirm_ban;

            if (confirmMessage && !window.confirm(confirmMessage)) {
                e.preventDefault();
                return false;
            }

            selectedItems.each(function () {
                $('<input>', {
                    type: 'hidden',
                    name: 'selected_items[]',
                    value: $(this).val()
                }).appendTo('#bulk-action-form');
            });
        });

        $('#bulk-action-selector').on('change', function () {
            if ($(this).val() === 'bulk_ban') {
                $('.ban-options').show();
            } else {
                $('.ban-options').hide();
            }
        });

        $('.wp-list-table').on('click', 'a.ban-with-duration', function (e) {
            e.preventDefault();
            var url = $(this).attr('href');
            var name = $(this).data('name') || '';

            var $modal = $(
                '<div class="ban-duration-modal-overlay">' +
                    '<div class="ban-duration-modal-card">' +
                        '<h3>' + InitChatMgmt.i18n.ban_user + '</h3>' +
                        '<p><strong>' + InitChatMgmt.i18n.user_label + '</strong> ' + $('<div/>').text(name).html() + '</p>' +
                        '<p><label>' + InitChatMgmt.i18n.ban_duration_label + '<br>' +
                            '<input type="number" id="ban-duration-input" value="24" min="0" class="ban-duration-input"></label></p>' +
                        '<p>' +
                            '<button class="button button-primary" id="confirm-ban">' + InitChatMgmt.i18n.ban_user + '</button> ' +
                            '<button class="button" id="cancel-ban">' + InitChatMgmt.i18n.cancel + '</button>' +
                        '</p>' +
                    '</div>' +
                '</div>'
            );

            $('body').append($modal);

            $modal.on('click', function (ev) {
                if (ev.target === this) $modal.remove();
            });
            $modal.find('#cancel-ban').on('click', function () {
                $modal.remove();
            });
            $modal.find('#confirm-ban').on('click', function () {
                var duration = parseInt($('#ban-duration-input').val(), 10);
                if (isNaN(duration) || duration < 0) duration = 0;
                window.location.href = url + '&duration=' + duration;
            });
        });

        $(document).on('click', '.init-chat-view-full-message', function (e) {
            e.preventDefault();
            var msg = $(this).data('message') || '';
            alert(msg);
        });

        $(document).on('click', '.init-chat-confirm-delete', function (e) {
            if (!window.confirm(InitChatMgmt.i18n.are_you_sure_delete_single)) e.preventDefault();
        });
        $(document).on('click', '.init-chat-confirm-unban', function (e) {
            if (!window.confirm(InitChatMgmt.i18n.are_you_sure_unban)) e.preventDefault();
        });
        $(document).on('click', '.init-chat-confirm-cleanup', function (e) {
            if (!window.confirm(InitChatMgmt.i18n.run_cleanup_confirm)) e.preventDefault();
        });

        toggleBulkActions();
    });
})(jQuery);
