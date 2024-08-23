$(document).ready(function() {
    permissionControl();
});
function permissionControl() {
    $.ajax({
		url: "/payroll/dashboard/actions/dashboard.php",
		type: "POST",
		data: {
			action: 'permissionControl'
		},
		dataType: "JSON",
		type: 'POST',
		success: function(result){
            var status = result.status;
            if(status == 'E') {
                window.location = "/control_role_alert.php";
            } else {
                if(status == 'N') {
                    $(".permissionModal").modal();
                    $(".permissionModal .modal-body").html(passCodeTemplate);
                    $(".p-email").html(result.email);
                    $(".permission_key").html(result.permission_key);
                } else {
                    $("#tb_pay").removeClass("hidden");
                    buildPay();
                }
            }
        }
    });
}
var passCodeTemplate = `
    <h1 class="text-center text-orange" style="font-size:48px;"><i class="fas fa-user-shield"></i></h1>
    <h4 class="text-center" lang="en">Passcode Verification</h4>
    <p class="text-center"><span lang="en">Enter the Passcode you received at</span> <span class="text-orange p-email"></span></p>
    <p class="text-center hidden"><code class="permission_key"></code></p>
    <div class="input-field">
        <input type="text" class="passcode" id="box1" maxlength="1" onclick="this.select();" autocomplete="off" onkeypress="return isNumberKey(this, event);" onkeyup="handleKeyUp(event, this, 'box2', null)">
        <input type="text" class="passcode" id="box2" maxlength="1" onclick="this.select();" disabled autocomplete="off" onkeypress="return isNumberKey(this, event);" onkeyup="handleKeyUp(event, this, 'box3', 'box1')">
        <input type="text" class="passcode" id="box3" maxlength="1" onclick="this.select();" disabled autocomplete="off" onkeypress="return isNumberKey(this, event);" onkeyup="handleKeyUp(event, this, 'box4', 'box2')">
        <input type="text" class="passcode" id="box4" maxlength="1" onclick="this.select();" disabled autocomplete="off" onkeypress="return isNumberKey(this, event);" onkeyup="handleKeyUp(event, this, 'box5', 'box3')">
        <input type="text" class="passcode" id="box5" maxlength="1" onclick="this.select();" disabled autocomplete="off" onkeypress="return isNumberKey(this, event);" onkeyup="handleKeyUp(event, this, 'box6', 'box4')">
        <input type="text" class="passcode" id="box6" maxlength="1" onkeyup="getTab()" onclick="this.select();" disabled onkeypress="return isNumberKey(this, event);" onkeyup="handleKeyUp(event, this, null, 'box5')">
    </div>
    <p class="text-center" style="margin-top:25px;">
        <button type="button" class="btn btn-white text-orange" onclick="resendPasscode();"><i class="fas fa-paper-plane"></i> <span lang="en">Resent Passcode</span></button> 
        <button type="button" class="btn btn-white" lang="en" onclick="switchApp('origami-main-app')">Cancel</button>
    </p>
`;
function switchApp(object_class) {
    event.stopPropagation();
    swal({
        html:true,
        title: window.lang.translate("Are you sure?"),
        text: 'Do you want switch to Origami?',
        type: "warning",
        showCancelButton: true,
        closeOnConfirm: false,
        confirmButtonText: window.lang.translate("Yes"),
        cancelButtonText: window.lang.translate("Cancel"),	
        confirmButtonColor: '#FF9900',
        cancelButtonColor: '#CCCCCC',
        showLoaderOnConfirm: true,
    },
    function(isConfirm){
        if (isConfirm) {
			$("."+object_class).click();
		} else {
            swal.close();
        }
	});
}
function handleKeyUp(event, current, nextFieldID, prevFieldID) {
    const value = current.value;
    if (value.length >= current.maxLength && nextFieldID) {
        $("#"+nextFieldID).attr("disabled",false);
        document.getElementById(nextFieldID).focus();
    } else if (event.key === "Backspace" && prevFieldID) {
        document.getElementById(prevFieldID).focus();
        $("#"+current.id).attr("disabled",true);
    }
}
function getTab() {
    var box1 = $("#box1").val();
    var box2 = $("#box2").val();
    var box3 = $("#box3").val();
    var box4 = $("#box4").val();
    var box5 = $("#box5").val();
    var box6 = $("#box6").val();
    var passCode = '';
    passCode += (box1) ? box1 : 'X';
    passCode += (box2) ? box2 : 'X';
    passCode += (box3) ? box3 : 'X';
    passCode += (box4) ? box4 : 'X';
    passCode += (box5) ? box5 : 'X';
    passCode += (box6) ? box6 : 'X';
    $.ajax({
		url: "/payroll/dashboard/actions/dashboard.php",
		type: "POST",
		data: {
			action: 'checkPassCode',
            passCode: passCode
		},
		dataType: "JSON",
		type: 'POST',
		success: function(result){
            if(result.status == true) {
                swal({type: 'success',title: "Successfully",text: "", showConfirmButton: false,timer: 2000});	
                $(".permissionModal").modal("hide");
            } else {
                swal({type: 'error',title: "Sorry...",text: "Something went wrong! Please try again later",showConfirmButton: false,timer: 3000});
                $(".passcode").val("");
                $("#box2").attr("disabled",true);
                $("#box3").attr("disabled",true);
                $("#box4").attr("disabled",true);
                $("#box5").attr("disabled",true);
                $("#box6").attr("disabled",true);
            }
        }
    });
}
function resendPasscode() {
    $.ajax({
		url: "/payroll/dashboard/actions/dashboard.php",
		type: "POST",
		data: {
			action: 'resendPasscode',
		},
		dataType: "JSON",
		type: 'POST',
		success: function(result){
            if(result.status == true) {
                swal({type: 'success',title: "Successfully",text: "", showConfirmButton: false,timer: 2000});
                permissionControl();
            }
        }
    });
}
function isNumberKey(txt, evt) {
	var charCode = (evt.which) ? evt.which : evt.keyCode;
	if (charCode == 46) {
		if (txt.value.indexOf('.') === -1) {
			return true;
		} else {
			return false;
		}
	} else {
		if (charCode > 31 && (charCode < 48 || charCode > 57))
			return false;
	}
	return true;
}