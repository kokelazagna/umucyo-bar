// Login page behaviour: show/hide password, quick checks before sending,
// "Remember me" (remembers the USERNAME on this device only) and the forgot-password note.
(function () {
  var form          = document.getElementById("loginForm");
  var usernameInput = document.getElementById("username");
  var passwordInput = document.getElementById("password");
  var togglePassword = document.getElementById("togglePassword");
  var rememberInput = document.getElementById("remember");
  var forgotButton  = document.getElementById("forgotButton");
  var formMessage   = document.getElementById("formMessage");
  var submitButton  = document.getElementById("submitButton");
  var submitLabel   = document.getElementById("submitLabel");

  var STORAGE_KEY = "umucyo_remembered_username";

  // Remember me: fill the username saved on a previous visit
  try {
    var savedUsername = localStorage.getItem(STORAGE_KEY);
    if (savedUsername) {
      usernameInput.value = savedUsername;
      rememberInput.checked = true;
      passwordInput.focus();
    }
  } catch (e) { /* storage not available: ignore */ }

  // Eye button: show / hide the password
  togglePassword.addEventListener("click", function () {
    var reveal = passwordInput.type === "password";
    passwordInput.type = reveal ? "text" : "password";
    togglePassword.setAttribute("aria-pressed", String(reveal));
    togglePassword.setAttribute("aria-label", reveal ? "Hide password" : "Show password");
  });

  // On submit: check the two fields, then let the form go to the server (PHP checks the password)
  form.addEventListener("submit", function (event) {
    formMessage.className = "form-message";

    var username = usernameInput.value.trim();
    if (!username || !passwordInput.value) {
      event.preventDefault();
      formMessage.textContent = "Enter your username and password to continue.";
      formMessage.classList.add("error");
      (!username ? usernameInput : passwordInput).focus();
      return;
    }

    try {
      if (rememberInput.checked) {
        localStorage.setItem(STORAGE_KEY, username);
      } else {
        localStorage.removeItem(STORAGE_KEY);
      }
    } catch (e) { /* ignore */ }

    submitButton.disabled = true;          // avoids sending the form twice
    submitLabel.textContent = "Signing in…";
  });

  // Back button: the page can come back from memory with the button still disabled
  window.addEventListener("pageshow", function () {
    submitButton.disabled = false;
    submitLabel.textContent = "Sign in";
  });

  forgotButton.addEventListener("click", function () {
    formMessage.className = "form-message";
    formMessage.textContent = "Please contact your system administrator to reset your password.";
  });
})();
