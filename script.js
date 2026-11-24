document.addEventListener("DOMContentLoaded", () => {
  /* =========================================
     MENÚ MÓVIL
  ========================================= */

  const menuToggle = document.querySelector(".menu-toggle");
  const mainNav = document.querySelector("#main-navigation");

  if (menuToggle && mainNav) {
    const openMenu = () => {
      mainNav.classList.add("open");
      menuToggle.setAttribute("aria-expanded", "true");
      menuToggle.setAttribute("aria-label", "Tancar el menú");
      menuToggle.innerHTML = "✕";
    };

    const closeMenu = () => {
      mainNav.classList.remove("open");
      menuToggle.setAttribute("aria-expanded", "false");
      menuToggle.setAttribute("aria-label", "Obrir el menú");
      menuToggle.innerHTML = "☰";
    };

    menuToggle.addEventListener("click", (e) => {
      e.preventDefault();
      mainNav.classList.contains("open") ? closeMenu() : openMenu();
    });

    // Cerrar al seleccionar un enlace
    mainNav.querySelectorAll("a").forEach(link => {
      link.addEventListener("click", closeMenu);
    });

    // Cerrar al hacer clic fuera
    document.addEventListener("click", (e) => {
      if (
        mainNav.classList.contains("open") &&
        !mainNav.contains(e.target) &&
        !menuToggle.contains(e.target)
      ) {
        closeMenu();
      }
    });

    // Cerrar con Escape
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && mainNav.classList.contains("open")) {
        closeMenu();
        menuToggle.focus();
      }
    });

    // Cerrar al volver a escritorio
    window.addEventListener("resize", () => {
      if (window.innerWidth > 680) closeMenu();
    });
  }

  /* =========================================
     FORMULARIOS DE ADHESIÓN
  ========================================= */

  const adhesionOptions = document.querySelectorAll(
    'input[name="adhesion-type"]'
  );

  const entityForm = document.getElementById("entity-form");
  const individualForm = document.getElementById("individual-form");

  function toggleAdhesionForm() {
    const selectedOption = document.querySelector(
      'input[name="adhesion-type"]:checked'
    );

    const isEntity = selectedOption?.value === "entity";

    if (entityForm) entityForm.hidden = !isEntity;
    if (individualForm) individualForm.hidden = isEntity;
  }

  adhesionOptions.forEach(option => {
    option.addEventListener("change", toggleAdhesionForm);
  });

  // Estado inicial
  toggleAdhesionForm();

  /* =========================================
     ENVÍO DE FORMULARIOS
  ========================================= */

  [entityForm, individualForm].forEach(form => {
    if (!form) return;

    form.addEventListener("submit", async (e) => {
      e.preventDefault();

      const formData = new FormData(form);
      const messageDiv = form.querySelector(".form-message");
      const button = form.querySelector('button[type="submit"]');

      if (!button) return;

      const originalText = button.innerHTML;

      button.disabled = true;
      button.innerHTML = "Enviant...";

      if (messageDiv) {
        messageDiv.textContent = "";
        messageDiv.style.color = "";
      }

      try {
        const response = await fetch("process.php", {
          method: "POST",
          body: formData
        });

        // Leemos el texto primero para poder diagnosticar respuestas que no son JSON.
        const rawText = await response.text();
        let result;

        try {
          result = JSON.parse(rawText);
        } catch (parseError) {
          console.error(
            "Respuesta no válida de process.php (HTTP " + response.status + "):",
            rawText
          );
          throw new Error(
            "El servidor ha respost amb un error (HTTP " + response.status + ")."
          );
        }

        if (!response.ok) {
          throw new Error(result.message || "Error en l'enviament.");
        }

        if (messageDiv) {
          messageDiv.textContent = result.message;
          messageDiv.style.color = result.success ? "green" : "red";
          messageDiv.style.marginTop = "1rem";
          messageDiv.style.fontWeight = "500";
        }

        if (result.success) {
          form.reset();

          // Volver a mostrar el formulario de entidades
          const entityRadio = document.querySelector(
            'input[name="adhesion-type"][value="entity"]'
          );

          if (entityRadio) {
            entityRadio.checked = true;
            toggleAdhesionForm();
          }
        }

      } catch (error) {
        const isNetworkError = error instanceof TypeError;

        if (messageDiv) {
          messageDiv.textContent = isNetworkError
            ? "Error de connexió. Torna-ho a provar."
            : error.message || "Error en l'enviament. Torna-ho a provar.";
          messageDiv.style.color = "red";
          messageDiv.style.marginTop = "1rem";
        }

        console.error("Error enviando formulario:", error);

      } finally {
        button.disabled = false;
        button.innerHTML = originalText;
      }
    });
  });

  /* =========================================
     CONTADOR DE ADHESIONES
  ========================================= */

  async function actualizarContador() {
    const countEntitats = document.getElementById("count-entitats");
    const countPersones = document.getElementById("count-persones");
    const countTotal = document.getElementById("count-total");

    if (!countEntitats || !countPersones || !countTotal) return;

    try {
      const response = await fetch("get-counts.php");
      const data = await response.json();

      if (!response.ok || !data.success) return;

      animarNumero(countEntitats, data.entitats);
      animarNumero(countPersones, data.persones);
      animarNumero(countTotal, data.total);

    } catch (error) {
      console.error("Error cargando contador:", error);
    }
  }

  function animarNumero(element, valorFinal) {
    const valorInicial = parseInt(element.textContent.replace(/\D/g, "")) || 0;
    const duracion = 1200;
    const inicio = performance.now();

    function actualizar(tiempoActual) {
      const progreso = Math.min((tiempoActual - inicio) / duracion, 1);

      const valor = Math.floor(
        valorInicial + (valorFinal - valorInicial) * progreso
      );

      element.textContent = valor.toLocaleString("ca-ES");

      if (progreso < 1) {
        requestAnimationFrame(actualizar);
      }
    }

    requestAnimationFrame(actualizar);
  }

  actualizarContador();

  /* =========================================
     LISTAS PÚBLICAS DE ADHESIONES
  ========================================= */

  async function cargarListasPublicas() {
    const listaEntitats = document.getElementById("lista-entitats");
    const listaPersones = document.getElementById("lista-persones");

    if (!listaEntitats || !listaPersones) return;

    try {
      const response = await fetch("get-public.php");
      const data = await response.json();

      if (!response.ok || !data.success) {
        throw new Error("No s'han pogut carregar les dades.");
      }

      // Entidades
      listaEntitats.innerHTML = "";
      if (data.entitats?.length) {
        data.entitats.forEach(nombre => {
          const li = document.createElement("li");
          li.textContent = nombre;
          listaEntitats.appendChild(li);
        });
      } else {
        listaEntitats.innerHTML =
          '<li class="muted">Encara no hi ha entitats públiques.</li>';
      }

      // Personas
      listaPersones.innerHTML = "";
      if (data.persones?.length) {
        data.persones.forEach(nombre => {
          const li = document.createElement("li");
          li.textContent = nombre;
          listaPersones.appendChild(li);
        });
      } else {
        listaPersones.innerHTML =
          '<li class="muted">Encara no hi ha persones públiques.</li>';
      }

    } catch (error) {
      console.error("Error cargando listas públicas:", error);

      listaEntitats.innerHTML =
        '<li class="muted">Error en carregar les dades.</li>';

      listaPersones.innerHTML =
        '<li class="muted">Error en carregar les dades.</li>';
    }
  }

  cargarListasPublicas();

});