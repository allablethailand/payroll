$(function () {
    $('input[name="employee_type_radio"]').on('change', function () {
        var type = $(this).val();
        $('#employee_type').val(type);
        $('#sectionDomestic').toggleClass('d-none', type !== 'domestic');
        $('#sectionForeigner').toggleClass('d-none', type !== 'foreigner');
        $('#id_card_no').prop('required', type === 'domestic');
    }).filter(':checked').trigger('change');
    $('input[name="gender_radio"]').on('change', function () {
        $('#gender').val($(this).val());
    });
    $('#profile_photo_input').on('change', function (e) {
        var file = e.target.files[0];
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function (ev) {
            $('#profilePreview').attr('src', ev.target.result).removeClass('d-none');
            $('#profilePlaceholder').addClass('d-none');
        };
        reader.readAsDataURL(file);
    });
    function isValidThaiID(id) {
        if (!/^\d{13}$/.test(id)) return false;
        var sum = 0;
        for (var i = 0; i < 12; i++) {
            sum += parseInt(id.charAt(i), 10) * (13 - i);
        }
        var check = (11 - (sum % 11)) % 10;
        return check === parseInt(id.charAt(12), 10);
    }
    $('#id_card_no').on('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 13);
    }).on('blur', function () {
        var val = $(this).val();
        if (val.length === 0) {
            $(this).removeClass('is-invalid');
            return;
        }
        $(this).toggleClass('is-invalid', !isValidThaiID(val));
    });
    if ($.fn.datepicker) {
        $('.datepicker').datepicker({ format: 'dd/mm/yyyy', autoclose: true, todayHighlight: true });
    }
    $('#btnNextContact').on('click', function () {
        var contactTabEl = document.querySelectorAll('#employeeTabs button[data-bs-target="#employee-pane"]')[1];
        if (contactTabEl) {
            bootstrap.Tab.getOrCreateInstance(contactTabEl).show();
        }
    });
});