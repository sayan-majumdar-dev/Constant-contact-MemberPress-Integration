jQuery(document).ready(function ($) {
    $(".nav-tab").click(function (e) {
        e.preventDefault();

        // Remove active class from all tabs
        $(".nav-tab").removeClass("nav-tab-active");
        $(this).addClass("nav-tab-active");

        // Hide all tab content
        $(".cc-tab-content").hide();

        // Show the selected tab content
        let targetTab = $(this).data("tab");
        $("#" + targetTab).fadeIn();

        // If Submission Logs tab is clicked, initialize or reload DataTable
        if (targetTab === "cc-entries") {
            if (!$.fn.DataTable.isDataTable("#cc-entries-table")) {
                initDataTable();
            } else {
                logsTable.ajax.reload();
            }
        }
    });

    let logsTable;

    function initDataTable() {
        logsTable = $("#cc-entries-table").DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: cc_ajax.ajax_url,
                type: "POST",
                data: { action: "fetch_ccmp_entries" }
            },
            columns: [
                { 
                    data: null, // ✅ Auto-generate serial numbers
                    render: function (data, type, row, meta) {
                        return meta.row + 1; // Serial No. starts from 1
                    }
                },
                { data: "email" },
                { data: "status" },
                { data: "date" }
            ],
            order: [[3, "desc"]], // ✅ Order by "date" (latest first)
            pageLength: 10,
            language: {
                emptyTable: "No entries found."
            }
        });
    }

    // Ensure the first visible tab is displayed properly
    $("#cc-settings").show();


    var connectButton = $('#cc_connect_button');
    var revokeButton = $('#cc_revoke_button');
    var buttonGroup = $('.button-group');
    var clientIdField = $('#cc_client_id');

    function showMessage(type, message) {
        var messageBox = $('<div class="cc-message notice-' + type + '">' + message + '</div>');
        $('.wrap').prepend(messageBox);
        messageBox.hide().fadeIn(300).delay(2000).fadeOut(500, function () {
            $(this).remove();
        });
    }

    function updateUI(authenticated) {
        if (authenticated) {
            connectButton.remove();
            if ($('#cc_revoke_button').length === 0) {
                buttonGroup.append('<button id="cc_revoke_button" class="button button-secondary">Revoke Access</button>');
                bindRevokeButton();
            }
            if ($('#cc_connected_status').length === 0) {
                clientIdField.after('<p id="cc_connected_status" style="color: green; font-weight: bold;">Connected ✅</p>');
            }
        } else {
            revokeButton.remove();
            $('#cc_connected_status').remove();
            if ($('#cc_connect_button').length === 0) {
                buttonGroup.append('<button id="cc_connect_button" class="button button-primary">Connect</button>');
                bindConnectButton();
            }
        }
    }

    connectButton.click(function (e) {
        e.preventDefault();
        connectButton.prop('disabled', true).text('Connecting...');

        $.ajax({
            type: 'POST',
            url: cc_ajax.ajax_url,
            data: { action: 'generate_auth_url' },
            success: function (response) {
                if (response.success) {
                    showMessage('success', 'Redirecting to Constant Contact authorization...');
                    setTimeout(function () {
                        window.location.href = response.data.auth_url;
                    }, 1500);
                } else {
                    showMessage('error', 'Error: ' + response.data.message);
                    connectButton.prop('disabled', false).text('Connect');
                }
            },
            error: function () {
                showMessage('error', 'An unexpected error occurred.');
                connectButton.prop('disabled', false).text('Connect');
            }
        });
    });

    function bindRevokeButton() {
        $('#cc_revoke_button').click(function (e) {
            e.preventDefault();
            if (!confirm("Are you sure you want to revoke access?")) return;

            $.ajax({
                type: 'POST',
                url: cc_ajax.ajax_url,
                data: { action: 'revoke_access', nonce: cc_ajax.nonce },
                success: function (response) {
                    if (response.success) {
                        showMessage('success', 'Access revoked successfully.');
                        updateUI(false);
                    } else {
                        showMessage('error', 'Failed to revoke access.');
                    }
                },
                error: function () {
                    showMessage('error', 'An unexpected error occurred.');
                }
            });
        });
    }

    function bindConnectButton() {
        $('#cc_connect_button').click(function (e) {
            e.preventDefault();
            $(this).prop('disabled', true).text('Connecting...');

            $.ajax({
                type: 'POST',
                url: cc_ajax.ajax_url,
                data: { action: 'generate_auth_url' },
                success: function (response) {
                    if (response.success) {
                        showMessage('success', 'Redirecting to Constant Contact authorization...');
                        setTimeout(function () {
                            window.location.href = response.data.auth_url;
                        }, 1500);
                    } else {
                        showMessage('error', 'Error: ' + response.data.message);
                        $('#cc_connect_button').prop('disabled', false).text('Connect');
                    }
                },
                error: function () {
                    showMessage('error', 'An unexpected error occurred.');
                    $('#cc_connect_button').prop('disabled', false).text('Connect');
                }
            });
        });
    }

    
});
