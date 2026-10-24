<?php
session_start();

// Pehle se login hai to seedha chat page par
if (!empty($_SESSION['mwhatsappID'])) {
    header("Location: whatsapp.php");
    exit;
}

$conn = mysqli_connect("localhost", "root", "", "whatsapp");

/**
 * Send a JSON response and stop the script.
 */
function respond(bool $status, string $message, array $errors = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'  => $status,
        'message' => $message,
        'errors'  => $errors,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!$conn) {
        respond(false, 'Database connection failed', ['Database connection failed']);
    }

    $phone = trim($_POST['phone'] ?? '');
    $pass  = (string) ($_POST['pass'] ?? ''); // password par trim nahi

    // ---------- Validation: collect ALL errors ----------
    $errors = [];

    if ($phone === '') {
        $errors[] = 'Phone number is required';
    } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
        $errors[] = 'Please enter a valid phone number (10 digits)';
    }

    if ($pass === '') {
        $errors[] = 'Password is required';
    }

    if (!empty($errors)) {
        respond(false, $errors[0], $errors);
    }

    // ---------- Login check ----------
    $stmt = $conn->prepare("SELECT id, pass, status FROM usernumber WHERE phone = ? LIMIT 1");
    $stmt->bind_param("s", $phone);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $ok = false;

    if ($user) {
        if (password_verify($pass, $user['pass'])) {
            // Hashed password
            $ok = true;
        } elseif (hash_equals((string) $user['pass'], $pass)) {
            // Purana plain-text password: login karwao aur hash mein badal do
            $ok = true;
            $newHash = password_hash($pass, PASSWORD_DEFAULT);
            $up = $conn->prepare("UPDATE usernumber SET pass = ? WHERE id = ?");
            $up->bind_param("si", $newHash, $user['id']);
            $up->execute();
            $up->close();
        }
    }

    // Phone ya password galat, dono ke liye ek hi message (security)
    if (!$ok) {
        respond(false, 'Invalid phone number or password', ['Invalid phone number or password']);
    }

    if ($user['status'] !== 'active') {
        respond(false, 'Your account is not active', ['Your account is not active']);
    }

    session_regenerate_id(true);
    $_SESSION['mwhatsappID'] = (int) $user['id'];

    respond(true, 'Login successful');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: Arial, sans-serif;
        }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: linear-gradient(135deg, #667eea, #764ba2);
        }

        .form-card {
            width: 100%;
            max-width: 420px;
            background: #fff;
            padding: 35px;
            border-radius: 22px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, .20);
        }

        .icon {
            width: 65px;
            height: 65px;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 18px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: #fff;
            font-size: 28px;
        }

        h2 {
            text-align: center;
            color: #222;
            margin-bottom: 8px;
        }

        .subtitle {
            text-align: center;
            color: #777;
            font-size: 14px;
            margin-bottom: 28px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #333;
        }

        .phone-input {
            display: flex;
            border: 2px solid #e5e7eb;
            border-radius: 14px;
            overflow: hidden;
            background: #fafafa;
        }

        .phone-input:focus-within {
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, .12);
        }

        .country-code {
            display: flex;
            align-items: center;
            padding: 0 13px;
            background: #f3f4f6;
            border-right: 1px solid #ddd;
            font-weight: bold;
            color: #555;
        }

        input {
            width: 100%;
            border: none;
            outline: none;
            background: transparent;
            padding: 15px;
            font-size: 17px;
            color: #222;
        }

        input::placeholder {
            color: #aaa;
        }

        .hint {
            margin: 7px 0 18px;
            color: #999;
            font-size: 12px;
        }

        .field-gap {
            margin-bottom: 18px;
        }

        /* Error / success notification box */
        #message {
            display: none;
            margin-bottom: 15px;
            padding: 12px 14px;
            border-radius: 10px;
            font-size: 14px;
        }

        #message.error {
            display: block;
            color: #991b1b;
            background: #fee2e2;
            border: 1px solid #fca5a5;
        }

        #message.success {
            display: block;
            color: #166534;
            background: #dcfce7;
            border: 1px solid #86efac;
            text-align: center;
        }

        #message ul {
            margin: 0;
            padding-left: 18px;
        }

        #submitBtn {
            width: 100%;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: #fff;
            font-size: 16px;
            cursor: pointer;
            transition: all .3s ease;
        }

        #submitBtn:hover {
            background: linear-gradient(135deg, #764ba2, #667eea);
            transform: translateY(-2px);
        }

        #submitBtn:active {
            background: linear-gradient(135deg, #5a67d8, #6b3fa0);
            transform: translateY(0);
        }

        #submitBtn:disabled {
            opacity: .6;
            cursor: not-allowed;
            transform: none;
        }

        .privacy {
            margin-top: 18px;
            text-align: center;
            color: #999;
            font-size: 12px;
        }

        @media (max-width: 480px) {
            .form-card {
                padding: 28px 22px;
            }
        }
    </style>
</head>

<body>

<div class="form-card">
    <div class="icon"><img src="icon/whatsapp.svg" alt="" style="width:24px;"></div>
    <h2>Login</h2>
    <p class="subtitle">Enter your phone number and password</p>

    <form id="loginForm" novalidate>

        <label for="phone">Phone Number</label>
        <div class="phone-input">
            <span class="country-code">+92</span>
            <input type="tel" id="phone" name="phone" placeholder="300 1234567"
                   maxlength="10" inputmode="numeric" autocomplete="tel">
        </div>
        <p class="hint">Example: 3001234567</p>

        <label for="password">Password</label>
        <div class="phone-input field-gap">
            <input type="password" id="password" name="pass" placeholder="Password"
                   maxlength="50" autocomplete="current-password">
            <span class="country-code" id="eye" style="cursor:pointer;user-select:none;">
                <img src="icon/eye.svg" alt="Eye Icon">
            </span>
        </div>

        <!-- All errors / success show here -->
        <div id="message"></div>

        <button type="submit" id="submitBtn">Login →</button>
    </form>

    <p class="privacy">🔒 Your phone number is safe and private.</p>
</div>

<script src="https://code.jquery.com/jquery-4.0.0.js"
        integrity="sha256-9fsHeVnKBvqh3FB2HYu7g2xseAZ5MlN6Kz/qnkASV8U="
        crossorigin="anonymous"></script>

<script>
// Password show / hide
var pp = document.getElementById("password");
document.getElementById("eye").addEventListener('click', function () {
    pp.type = (pp.type === "password") ? "text" : "password";
});

$(document).ready(function () {

    const $message = $("#message");
    const $button  = $("#submitBtn");

    // Signup ke baad phone aur password input fields mein bhar do (sirf ek baar)
    try {
        const sp = sessionStorage.getItem("signup_phone");
        const sw = sessionStorage.getItem("signup_pass");
        if (sp) {
            $("#phone").val(sp);
            if (sw) $("#password").val(sw);
            sessionStorage.removeItem("signup_phone");
            sessionStorage.removeItem("signup_pass");
            showSuccess("Account created! Ab Login par click karo.");
        }
    } catch (err) {}

    function showErrors(errors) {
        const $list = $("<ul>");
        errors.forEach(function (err) {
            $list.append($("<li>").text(err));
        });
        $message.removeClass("success").addClass("error").empty().append($list);
    }

    function showSuccess(text) {
        $message.removeClass("error").addClass("success").text(text);
    }

    function clearMessage() {
        $message.removeClass("error success").empty();
    }

    $("#loginForm").on("submit", function (e) {
        e.preventDefault();
        clearMessage();
        $button.prop("disabled", true);

        $.ajax({
            url: "login.php",
            type: "POST",
            dataType: "json",
            data: {
                phone: $("#phone").val().trim(),
                pass:  $("#password").val()
            },

            success: function (response) {
                if (response.status === true) {
                    showSuccess("Login successful! Redirecting...");
                    // Button disabled rehne do, taake dobara click na ho
                    setTimeout(function () {
                        window.location.href = "whatsapp.php";
                    }, 1000);
                    return;
                }

                const errors = (response.errors && response.errors.length)
                    ? response.errors
                    : [response.message || "Something went wrong."];
                showErrors(errors);
                $button.prop("disabled", false);
            },

            error: function (xhr) {
                console.log(xhr.responseText);
                showErrors(["Something went wrong. Please try again."]);
                $button.prop("disabled", false);
            }
        });
    });
});
</script>

</body>
</html>