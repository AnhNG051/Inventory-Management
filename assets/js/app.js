// All page scripts. Sections: form validation, order lines, stock reasons, print button.

// ---------- Form validation ----------
// Applies to every form with the "validate" class. Rules come from the field attributes:
// required, minlength, maxlength, pattern, min, max, step, type="email", data-match,
// data-rule="password", data-label.

function getErrorBox(field) {
    let box = field.nextElementSibling;
    if (!box || !box.classList.contains("error")) {
        box = document.createElement("span");
        box.className = "error";
        field.insertAdjacentElement("afterend", box);
    }
    return box;
}

// returns an error message, or "" when the value is fine
function getMessage(field) {
    const value = field.value.trim();
    const label = field.dataset.label || field.name;

    if (value === "") {
        return field.required ? label + " is required" : "";
    }
    const min = field.getAttribute("minlength");
    const max = field.getAttribute("maxlength");
    if (min && value.length < Number(min)) return label + " must be at least " + min + " characters";
    if (max && value.length > Number(max)) return label + " must be at most " + max + " characters";

    const pattern = field.getAttribute("pattern");
    if (pattern && !new RegExp("^(?:" + pattern + ")$").test(value)) {
        return field.dataset.message || label + " has an invalid format";
    }
    if (field.type === "email" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
        return "Enter a valid email address";
    }
    if (field.type === "number") {
        const number = Number(value);
        if (isNaN(number)) return label + " must be a number";
        if (field.getAttribute("step") === "1" && !Number.isInteger(number)) return label + " must be a whole number";
        if (field.min !== "" && number < Number(field.min)) return label + " must be at least " + field.min;
        if (field.max !== "" && number > Number(field.max)) return label + " must be at most " + field.max;
    }
    if (field.dataset.rule === "password") {
        const strong = /[A-Z]/.test(value) && /[a-z]/.test(value) && /[0-9]/.test(value);
        if (value.length < 8 || !strong) return "Password needs 8+ characters with uppercase, lowercase and a number";
    }
    if (field.dataset.match && value !== document.querySelector(field.dataset.match).value) {
        return "Passwords do not match";
    }
    return "";
}

// show or clear the message beside the field; returns true when valid
function checkField(field) {
    const message = getMessage(field);
    getErrorBox(field).textContent = message;
    field.classList.toggle("invalid", message !== "");
    return message === "";
}

document.querySelectorAll("form.validate").forEach((form) => {
    // fields are looked up when needed, so rows added later (order lines) are checked too
    const selector = "input:not([type=hidden]):not([type=checkbox]), select, textarea";

    form.addEventListener("focusout", (event) => {
        if (event.target.matches(selector)) checkField(event.target);
    });
    form.addEventListener("input", (event) => {
        if (event.target.matches(selector) && event.target.classList.contains("invalid")) checkField(event.target);
    });

    form.addEventListener("submit", (event) => {
        let firstBad = null;
        form.querySelectorAll(selector).forEach((field) => {
            if (!checkField(field) && !firstBad) firstBad = field;
        });
        if (firstBad) {
            event.preventDefault();
            firstBad.focus();
        }
    });
});

// ask before deleting
document.querySelectorAll("form.confirm-delete").forEach((form) => {
    form.addEventListener("submit", (event) => {
        if (!confirm("Delete this record? This cannot be undone.")) event.preventDefault();
    });
});

// ask before actions that carry their own question, e.g. <form data-confirm="Reset this password?">
document.querySelectorAll("form[data-confirm]").forEach((form) => {
    form.addEventListener("submit", (event) => {
        if (!confirm(form.dataset.confirm)) event.preventDefault();
    });
});

// ---------- Order lines (add/remove lines, fill the price, show totals) ----------
const money = (n) => "$" + n.toFixed(2);

const lines = document.getElementById("lines");
if (lines) {
    const template = document.getElementById("line-template");

    function updateTotals() {
        let total = 0;
        lines.querySelectorAll("tr.line").forEach((row) => {
            const qty = parseFloat(row.querySelector(".line-qty").value) || 0;
            const price = parseFloat(row.querySelector(".line-price").value) || 0;
            row.querySelector(".line-total").textContent = money(qty * price);
            total += qty * price;
        });
        document.getElementById("order-total").textContent = money(total);
    }

    document.getElementById("add-line").addEventListener("click", () => {
        lines.appendChild(template.content.cloneNode(true));
        lines.lastElementChild.querySelector("select").focus();
    });

    lines.addEventListener("click", (event) => {
        if (!event.target.classList.contains("remove-line")) return;
        const row = event.target.closest("tr");
        if (lines.querySelectorAll("tr.line").length > 1) {
            row.remove();
        } else {   // keep one empty row
            row.querySelectorAll("input").forEach((input) => (input.value = ""));
            row.querySelector("select").value = "";
        }
        updateTotals();
    });

    // choosing a product fills in its cost or price
    lines.addEventListener("change", (event) => {
        if (!event.target.classList.contains("line-product")) return;
        const option = event.target.selectedOptions[0];
        const price = event.target.closest("tr").querySelector(".line-price");
        price.value = option && option.dataset.price ? option.dataset.price : "";
        updateTotals();
    });

    lines.addEventListener("input", updateTotals);
    updateTotals();
}

// ---------- Stock page: only show reasons that fit Stock In / Stock Out ----------
const typeSelect = document.getElementById("type");
const reasonSelect = document.getElementById("reason");
if (typeSelect && reasonSelect) {
    function showReasons() {
        let chosenIsHidden = false;
        reasonSelect.querySelectorAll("option[data-type]").forEach((option) => {
            const show = option.dataset.type.split(" ").includes(typeSelect.value);
            option.hidden = !show;
            option.disabled = !show;
            if (!show && option.selected) chosenIsHidden = true;
        });
        if (chosenIsHidden) reasonSelect.value = "";
    }
    typeSelect.addEventListener("change", showReasons);
    showReasons();
}

// ---------- Print button (inline onclick is blocked by the page security policy) ----------
document.querySelectorAll("[data-print]").forEach((button) => {
    button.addEventListener("click", () => window.print());
});
