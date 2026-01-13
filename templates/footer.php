    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.10.1/main.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <!-- Custom JS -->
    <script src="<?php echo SITE_URL; ?>assets/js/main.js"></script>
    
    <script>
        // Global functions
        function showLoading() {
            Swal.fire({
                title: 'Memproses...',
                allowOutsideClick: false,
                showConfirmButton: false,
                willOpen: () => {
                    Swal.showLoading();
                }
            });
        }
        
        function showSuccess(message) {
            Swal.fire({
                icon: 'success',
                title: 'Berhasil',
                text: message,
                timer: 3000,
                showConfirmButton: false
            });
        }
        
        function showError(message) {
            Swal.fire({
                icon: 'error',
                title: 'Gagal',
                text: message
            });
        }
        
        function confirmAction(message, callback) {
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
        }
        
            // Initialize DataTables and other UI components (guard against double-init)
            $(document).ready(function() {
                // Only initialize DataTable if not already initialized by assets/js/main.js
                if ($.fn.DataTable && !$.fn.DataTable.isDataTable('.datatable')) {
                    $('.datatable').DataTable({
                        language: {
                            url: '//cdn.datatables.net/plug-ins/1.11.5/i18n/id.json'
                        },
                        pageLength: 10,
                        responsive: true,
                        order: []
                    });
                }

                // Initialize Select2 if not already initialized
                if ($.fn.select2 && $('.select2').length && !$('.select2').hasClass('select2-hidden-accessible')) {
                    $('.select2').select2({
                        theme: 'bootstrap-5',
                        width: '100%'
                    });
                }

                // Auto-dismiss alerts
                setTimeout(function() {
                    $('.alert').alert('close');
                }, 5000);

                // Tooltips
                $('[data-bs-toggle="tooltip"]').tooltip();

                // Popovers
                $('[data-bs-toggle="popover"]').popover();
            });
    </script>
</body>
</html>