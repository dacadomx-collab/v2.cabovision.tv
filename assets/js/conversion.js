/**
 * Componente: conversion.js
 * Ubicación: C:\xampp\htdocs\CaboVision.tv\assets\js\conversion.js
 * Objetivo: Validación estricta anti-nulos y preparación del ecosistema NLP (Gemini)
 */

$(document).ready(function() {
    // Inmunización del formulario de captación rápida de leads en menos de 1 minuto
    $("#form-lead-conversion").validate({
        rules: {
            lead_name: {
                required: true,
                minlength: 3
            },
            lead_email: {
                required: true,
                email: true
            },
            lead_phone: {
                required: true,
                digits: true,
                minlength: 10,
                maxlength: 10
            }
        },
        messages: {
            lead_name: { required: "El nombre es mandatorio para el registro." },
            lead_email: { required: "Identificación de correo electrónico requerida." },
            lead_phone: { required: "Se necesita un número celular válido de 10 dígitos." }
        },
        errorElement: "span",
        errorClass: "badge danger",
        submitHandler: function(form) {
            // El payload cruza limpio una vez superada la barrera de jquery.validate.min.js
            form.submit();
        }
    });

    // Inicialización del anclaje de interfaz para el módulo conversacional bicultural (AURA AI / Gemini)
    initAuraNLPContainer();
});

function initAuraNLPContainer() {
    console.log("[AURA CORE] Inicializando interfaz reactiva para la burbuja NLP...");
    // El contenedor queda a la espera del script asíncrono del motor inteligente de respuestas
    const nlpBubbleHTML = `
        <div id="aura-nlp-bubble-container" style="position: fixed; bottom: 20px; right: 20px; z-index: 9999;">
            <!-- Hook de montaje de Gemini AI -->
            <div class="aura-ai-trigger" style="cursor: pointer; background: #10b981; padding: 15px; border-radius: 50%; box-shadow: 0 4px 10px rgba(0,0,0,0.15);">
                <img src="${window.BASE_PATH || ''}/assets/img/logocabovis_glow.png" style="width: 30px; height: 30px;" alt="AURA Core AI">
            </div>
        </div>
    `;
    $('body').append(nlpBubbleHTML);
}