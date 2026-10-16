// =====================================================================
// assets/js/dialogs.js
// SweetAlert2 helpers
// =====================================================================

/**
 * Confirmation dialog. Returns true if user confirms.
 */
async function confirmDialog(message, options = {}) {
    const result = await Swal.fire({
        title: options.title || "Are you sure?",
        text: message,
        icon: options.icon || "question",
        showCancelButton: true,
        confirmButtonText: options.confirmText || "Yes, proceed",
        cancelButtonText: options.cancelText || "Cancel",
        confirmButtonColor: "#0038A8",
        cancelButtonColor: "#6b7280",
        reverseButtons: true,
        focusCancel: true,
    });
    return result.isConfirmed;
}

/**
 * Info / error / success dialog.
 */
async function alertDialog(message, type = "info", title = null) {
    const titles = {
        success: title || "Success",
        error:   title || "Oops!",
        warning: title || "Warning",
        info:    title || "Note",
    };
    await Swal.fire({
        title: titles[type],
        text: message,
        icon: type,
        confirmButtonColor: "#0038A8",
        confirmButtonText: "OK",
    });
}

/**
 * Toast notification — slides in from top-right.
 */
function showToast(message, type = "success") {
    Swal.fire({
        toast: true,
        position: "top-end",
        icon: type,
        title: message,
        showConfirmButton: false,
        timer: 2500,
        timerProgressBar: true,
        didOpen: (toast) => {
            toast.addEventListener("mouseenter", Swal.stopTimer);
            toast.addEventListener("mouseleave", Swal.resumeTimer);
        },
    });
}