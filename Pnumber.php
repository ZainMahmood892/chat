<?php
session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Pehle se login hai to seedha chat page par
if (!empty($_SESSION['mwhatsappID'])) {
    header("Location: whatsapp.php");
    exit;
}

function respond(bool $status, string $message, array $errors = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => $status, 'message' => $message, 'errors' => $errors]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $phone = trim($_POST['phone'] ?? '');
    $pass  = (string) ($_POST['pass'] ?? ''); // password par trim nahi

    // ---------- Validation ----------
    $errors = [];

    if ($phone === '') {
        $errors[] = 'Phone number is required';
    } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
        $errors[] = 'Please enter a valid phone number (10 digits)';
    }

    if ($pass === '') {
        $errors[] = 'Password is required';
    } elseif (strlen($pass) < 8) {
        $errors[] = 'Password must be at least 8 characters long';
    }

    if ($errors) {
        respond(false, $errors[0], $errors);
    }

    try {
        $conn = mysqli_connect("localhost", "root", "", "whatsapp");

        // ---------- Duplicate check ----------
        $stmt = $conn->prepare("SELECT id FROM usernumber WHERE phone = ?");
        $stmt->bind_param("s", $phone);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();

        if ($exists) {
            respond(false, 'Phone number already exists', ['Phone number already exists']);
        }

        // ---------- Save user + apna contact (dono ya koi nahi) ----------
        $hash   = password_hash($pass, PASSWORD_DEFAULT);
        $status = 'active';
        $date   = date('Y-m-d H:i:s');
        $code   = '92';

        $conn->begin_transaction();

        $stmt = $conn->prepare("INSERT INTO usernumber (phone, pass, status, datetime) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $phone, $hash, $status, $date);
        $stmt->execute();
        $userId = $conn->insert_id;
        $stmt->close();

        $stmt = $conn->prepare(
            "INSERT INTO person_usersave (user_id, person_id, c_code, phone, status) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("iisss", $userId, $userId, $code, $phone, $status);
        $stmt->execute();
        $stmt->close();

        $conn->commit();

        // ---------- Session mein save ----------
        session_regenerate_id(true);
        $_SESSION['mwhatsappID'] = (int) $userId;

        respond(true, 'Account created successfully');

    } catch (mysqli_sql_exception $ex) {
        if (isset($conn) && $conn instanceof mysqli) {
            try { $conn->rollback(); } catch (Throwable $t) {}
        }
        // 1062 = duplicate entry (UNIQUE index par race condition)
        if ($ex->getCode() === 1062) {
            respond(false, 'Phone number already exists', ['Phone number already exists']);
        }
        respond(false, 'Could not create account', ['Could not create account. Please try again.']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: Arial, sans-serif; }
        body { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; background: linear-gradient(135deg, #667eea, #764ba2); }
        .form-card { width: 100%; max-width: 420px; background: #fff; padding: 35px; border-radius: 22px; box-shadow: 0 20px 50px rgba(0,0,0,.2); }
        .icon { width: 65px; height: 65px; margin: 0 auto 20px; display: flex; align-items: center; justify-content: center; border-radius: 18px; background: linear-gradient(135deg, #667eea, #764ba2); }
        h2 { text-align: center; color: #222; margin-bottom: 8px; }
        .subtitle { text-align: center; color: #777; font-size: 14px; margin-bottom: 28px; }
        label { display: block; margin-bottom: 8px; font-weight: bold; color: #333; }
        .phone-input { display: flex; border: 2px solid #e5e7eb; border-radius: 14px; overflow: hidden; background: #fafafa; }
        .phone-input:focus-within { border-color: #667eea; box-shadow: 0 0 0 4px rgba(102,126,234,.12); }
        .country-code { display: flex; align-items: center; padding: 0 13px; background: #f3f4f6; border-right: 1px solid #ddd; font-weight: bold; color: #555; }
        input { width: 100%; border: none; outline: none; background: transparent; padding: 15px; font-size: 17px; color: #222; }
        input::placeholder { color: #aaa; }
        .hint { margin: 7px 0 18px; color: #999; font-size: 12px; }
        .field-gap { margin-bottom: 18px; }
        #message { display: none; margin-bottom: 15px; padding: 12px 14px; border-radius: 10px; font-size: 14px; }
        #message.error { display: block; color: #991b1b; background: #fee2e2; border: 1px solid #fca5a5; }
        #message.success { display: block; color: #166534; background: #dcfce7; border: 1px solid #86efac; text-align: center; }
        #message ul { padding-left: 18px; }
        #submitBtn { width: 100%; padding: 12px 24px; border: none; border-radius: 6px; background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; font-size: 16px; cursor: pointer; transition: all .3s ease; }
        #submitBtn:hover { background: linear-gradient(135deg, #764ba2, #667eea); transform: translateY(-2px); }
        #submitBtn:disabled { opacity: .6; cursor: not-allowed; transform: none; }
        .privacy { margin-top: 18px; text-align: center; color: #999; font-size: 12px; }
        .login-link { margin-top: 12px; text-align: center; font-size: 14px; color: #555; }
        .login-link a { color: #667eea; text-decoration: none; font-weight: bold; }
        @media (max-width: 480px) { .form-card { padding: 28px 22px; } }
    </style>
</head>
<body>

<div class="form-card">
    <div class="icon"><img src="icon/whatsapp.svg" alt="" style="width:24px;"></div>
    <h2>Create Account</h2>
    <p class="subtitle">Enter your Pakistani mobile number</p>

    <form id="phoneForm" novalidate>
        <label for="phone">Phone Number</label>
        <div class="phone-input">
            <span class="country-code">+92</span>
            <input type="tel" id="phone" placeholder="300 1234567" maxlength="10" inputmode="numeric" autocomplete="tel">
        </div>
        <p class="hint">Example: 3001234567</p>

        <label for="password">Password</label>
        <div class="phone-input field-gap">
            <input type="password" id="password" placeholder="Password (min 8 characters)" maxlength="50" autocomplete="new-password">
            <span class="country-code" id="eye" style="cursor:pointer;user-select:none;"><img src="icon/eye.svg" alt="Show"></span>
        </div>

        <div id="message"></div>
        <button type="submit" id="submitBtn">Continue →</button>
    </form>

    <p class="login-link">Already have an account? <a href="login.php">Login</a></p>
    <p class="privacy">🔒 Your phone number is safe and private.</p>
</div>

<script src="https://code.jquery.com/jquery-4.0.0.js"
        integrity="sha256-9fsHeVnKBvqh3FB2HYu7g2xseAZ5MlN6Kz/qnkASV8U="
        crossorigin="anonymous"></script>
<script>
$(function () {
    const $msg = $("#message"), $btn = $("#submitBtn"), $pass = $("#password");

    // Password show / hide
    $("#eye").on("click", function () {
        $pass.attr("type", $pass.attr("type") === "password" ? "text" : "password");
    });

    function showErrors(errors) {
        const $list = $("<ul>");
        errors.forEach(e => $list.append($("<li>").text(e)));
        $msg.removeClass("success").addClass("error").empty().append($list);
    }

    $("#phoneForm").on("submit", function (e) {
        e.preventDefault();
        $msg.removeClass("error success").empty();
        $btn.prop("disabled", true);

        $.ajax({
            url: "Pnumber.php", type: "POST", dataType: "json",
            data: { phone: $("#phone").val().trim(), pass: $pass.val() },

            success: function (res) {
                if (res.status === true) {
                    $msg.removeClass("error").addClass("success").text("Account created! Redirecting...");
                    // Session ban chuka hai, seedha chat page par
                    setTimeout(() => { window.location.href = "whatsapp.php"; }, 1000);
                    return;
                }
                showErrors(res.errors && res.errors.length ? res.errors : [res.message || "Something went wrong."]);
                $btn.prop("disabled", false);
            },

            error: function (xhr) {
                console.log(xhr.responseText);
                showErrors(["Something went wrong. Please try again."]);
                $btn.prop("disabled", false);
            }
        });
    });
});
</script>

</body>
</html>