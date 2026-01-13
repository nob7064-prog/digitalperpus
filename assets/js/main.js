// Digital Library Main JavaScript

// Document Ready
$(document).ready(function() {
    // Initialize components
    initComponents();
    
    // Setup event listeners
    setupEventListeners();
    
    // Load initial data
    loadInitialData();
});

// Initialize Components
function initComponents() {
    // DataTables initialization
    if ($.fn.DataTable) {
        $('.datatable').DataTable({
            language: {
                url: '//cdn.datatables.net/plug-ins/1.11.5/i18n/id.json'
            },
            pageLength: 10,
            responsive: true,
            order: [],
            dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
                 '<"row"<"col-sm-12"tr>>' +
                 '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
            columnDefs: [
                { orderable: false, targets: 'no-sort' }
            ]
        });
    }
    
    // Select2 initialization
    if ($.fn.select2) {
        $('.select2').select2({
            theme: 'bootstrap-5',
            width: '100%',
            placeholder: 'Pilih...'
        });
    }
    
    // Tooltips
    if ($.fn.tooltip) {
        $('[data-bs-toggle="tooltip"]').tooltip();
    }
    
    // Popovers
    if ($.fn.popover) {
        $('[data-bs-toggle="popover"]').popover();
    }
    
    // Auto-dismiss alerts
    setTimeout(function() {
        $('.alert').alert('close');
    }, 5000);
}

// Setup Event Listeners
function setupEventListeners() {
    // Form validation
    $('form').on('submit', function(e) {
        const form = $(this);
        if (form.hasClass('needs-validation')) {
            if (!form[0].checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
            }
            form.addClass('was-validated');
        }
    });
    
    // Password toggle
    $('.password-toggle').on('click', function() {
        const input = $(this).parent().find('input');
        const icon = $(this).find('i');
        
        if (input.attr('type') === 'password') {
            input.attr('type', 'text');
            icon.removeClass('fa-eye').addClass('fa-eye-slash');
        } else {
            input.attr('type', 'password');
            icon.removeClass('fa-eye-slash').addClass('fa-eye');
        }
    });
    
    // File input preview
    $('input[type="file"]').on('change', function() {
        const input = $(this);
        const preview = input.data('preview');
        
        if (preview && input[0].files && input[0].files[0]) {
            const reader = new FileReader();
            
            reader.onload = function(e) {
                $(preview).attr('src', e.target.result);
            }
            
            reader.readAsDataURL(input[0].files[0]);
        }
    });
    
    // Real-time search
    $('.realtime-search').on('keyup', function() {
        const searchTerm = $(this).val().toLowerCase();
        const target = $(this).data('target');
        
        $(target).each(function() {
            const text = $(this).text().toLowerCase();
            $(this).toggle(text.includes(searchTerm));
        });
    });
}

// Load Initial Data
function loadInitialData() {
    // Load notifications
    loadNotifications();
    
    // Load stats if on dashboard
    if ($('.stat-card').length) {
        loadDashboardStats();
    }
}

// Load Notifications
function loadNotifications() {
    // This would typically make an AJAX call to get notifications
    // For now, we'll just update the count
    const notificationCount = $('.notification-badge');
    if (notificationCount.length) {
        // Simulate loading
        setTimeout(() => {
            // notificationCount.text('3'); // Example count
        }, 1000);
    }
}

// Load Dashboard Stats
function loadDashboardStats() {
    // This would typically make an AJAX call to get updated stats
    // For now, we'll just animate the numbers
    $('.stat-number').each(function() {
        const element = $(this);
        const finalValue = parseInt(element.text());
        
        // Only animate if it's a number
        if (!isNaN(finalValue)) {
            animateValue(element, 0, finalValue, 1000);
        }
    });
}

// Animate Number Counter
function animateValue(element, start, end, duration) {
    let startTimestamp = null;
    const step = (timestamp) => {
        if (!startTimestamp) startTimestamp = timestamp;
        const progress = Math.min((timestamp - startTimestamp) / duration, 1);
        const value = Math.floor(progress * (end - start) + start);
        element.text(value.toLocaleString());
        if (progress < 1) {
            window.requestAnimationFrame(step);
        }
    };
    window.requestAnimationFrame(step);
}

// Global Functions
window.DigitalLibrary = {
    // Show loading spinner
    showLoading: function(message = 'Memproses...') {
        Swal.fire({
            title: message,
            allowOutsideClick: false,
            showConfirmButton: false,
            willOpen: () => {
                Swal.showLoading();
            }
        });
    },
    
    // Show success message
    showSuccess: function(message, title = 'Berhasil') {
        Swal.fire({
            icon: 'success',
            title: title,
            text: message,
            timer: 3000,
            showConfirmButton: false
        });
    },
    
    // Show error message
    showError: function(message, title = 'Gagal') {
        Swal.fire({
            icon: 'error',
            title: title,
            text: message
        });
    },
    
    // Show confirmation dialog
    confirmAction: function(message, callback) {
        Swal.fire({
            title: 'Konfirmasi',
            text: message,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#4361ee',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Ya, Lanjutkan',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                callback();
            }
        });
    },
    
    // Format currency
    formatCurrency: function(amount) {
        return new Intl.NumberFormat('id-ID', {
            style: 'currency',
            currency: 'IDR',
            minimumFractionDigits: 0
        }).format(amount);
    },
    
    // Format date
    formatDate: function(date) {
        return new Date(date).toLocaleDateString('id-ID', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
    },
    
    // Truncate text
    truncateText: function(text, maxLength = 100) {
        if (text.length <= maxLength) return text;
        return text.substr(0, maxLength) + '...';
    },
    
    // Copy to clipboard
    copyToClipboard: function(text) {
        navigator.clipboard.writeText(text).then(() => {
            this.showSuccess('Teks berhasil disalin ke clipboard');
        }).catch(err => {
            this.showError('Gagal menyalin teks');
        });
    },
    
    // Download file
    downloadFile: function(url, filename) {
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    },
    
    // Print element
    printElement: function(elementId) {
        const printContents = document.getElementById(elementId).innerHTML;
        const originalContents = document.body.innerHTML;
        
        document.body.innerHTML = printContents;
        window.print();
        document.body.innerHTML = originalContents;
        location.reload();
    }
};

// Make functions globally available
window.showLoading = DigitalLibrary.showLoading;
window.showSuccess = DigitalLibrary.showSuccess;
window.showError = DigitalLibrary.showError;
window.confirmAction = DigitalLibrary.confirmAction;

// Keyboard shortcuts
$(document).on('keydown', function(e) {
    // Ctrl + F for search
    if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
        e.preventDefault();
        $('.search-input').focus();
    }
    
    // Escape to close modals
    if (e.key === 'Escape') {
        $('.modal').modal('hide');
    }
});

// Handle offline/online status
window.addEventListener('online', function() {
    DigitalLibrary.showSuccess('Koneksi internet telah pulih');
});

window.addEventListener('offline', function() {
    DigitalLibrary.showError('Anda sedang offline. Beberapa fitur mungkin tidak tersedia');
});