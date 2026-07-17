<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * [kiwi_create_board] — submission form (login required).
 * Consumes: $gate (array|null — non-null means logged out), $settings.
 */
?>
<div class="ke-board">
<?php if ( $gate ) : ?>

    <div class="ke-board-gate">
        <span class="ke-board-gate__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="3"/><path d="M3 9h18M8 13h5M8 16h8"/></svg>
        </span>
        <h2 class="ke-board-gate__title">Publica en el board</h2>
        <p class="ke-board-gate__message"><?php echo esc_html( $gate['message'] ); ?></p>
        <a class="ke-board-btn ke-board-btn--primary" href="<?php echo esc_url( $gate['login_url'] ); ?>">Iniciar sesión</a>
        <?php if ( ! empty( $gate['register_url'] ) ) : ?>
            <p class="ke-board-gate__register">¿No tienes cuenta? <a href="<?php echo esc_url( $gate['register_url'] ); ?>">Regístrate</a></p>
        <?php endif; ?>
    </div>

<?php else : ?>

    <form class="ke-board-form" data-ke-board-form novalidate>
        <h2 class="ke-board-form__title">Publica una actividad</h2>
        <p class="ke-board-form__hint">Tu actividad será revisada antes de aparecer en el board.</p>

        <div class="ke-board-field">
            <label for="ke-bf-title">Título de la actividad *</label>
            <input type="text" id="ke-bf-title" name="title" maxlength="150" required>
        </div>

        <div class="ke-board-field">
            <label for="ke-bf-image">Imagen principal *</label>
            <input type="file" id="ke-bf-image" name="main_image" accept="image/jpeg,image/png,image/webp" required>
            <p class="ke-board-field__hint">JPG, PNG o WEBP · máx. <?php echo esc_html( (int) $settings['max_file_mb'] ); ?> MB</p>
        </div>

        <div class="ke-board-field">
            <label for="ke-bf-description">Descripción *</label>
            <textarea id="ke-bf-description" name="description" rows="5" required></textarea>
        </div>

        <div class="ke-board-field">
            <label for="ke-bf-location">Ubicación *</label>
            <input type="text" id="ke-bf-location" name="location" required>
        </div>

        <div class="ke-board-field-row">
            <div class="ke-board-field">
                <label for="ke-bf-date">Fecha *</label>
                <input type="date" id="ke-bf-date" name="date" required>
            </div>
            <div class="ke-board-field">
                <label for="ke-bf-time">Hora *</label>
                <input type="time" id="ke-bf-time" name="time" required>
            </div>
        </div>

        <div class="ke-board-field">
            <label for="ke-bf-gallery">Imágenes adicionales</label>
            <input type="file" id="ke-bf-gallery" name="gallery" accept="image/jpeg,image/png,image/webp" multiple
                   data-max-files="<?php echo esc_attr( (int) $settings['max_gallery'] ); ?>">
            <p class="ke-board-field__hint">Opcional · hasta <?php echo esc_html( (int) $settings['max_gallery'] ); ?> imágenes</p>
        </div>

        <fieldset class="ke-board-organiza">
            <legend>Organiza</legend>
            <div class="ke-board-field">
                <label for="ke-bf-university">Universidad *</label>
                <input type="text" id="ke-bf-university" name="university" required>
            </div>
            <div class="ke-board-field">
                <label for="ke-bf-organizer">Grupo o persona que organiza *</label>
                <input type="text" id="ke-bf-organizer" name="organizer" required>
            </div>
            <div class="ke-board-field">
                <label for="ke-bf-contact">Contacto *</label>
                <input type="text" id="ke-bf-contact" name="contact" required>
                <p class="ke-board-field__hint">Este contacto será visible públicamente.</p>
            </div>
        </fieldset>

        <div class="ke-board-form__msg" data-ke-board-msg hidden></div>

        <button type="submit" class="ke-board-btn ke-board-btn--primary ke-board-form__submit">Enviar actividad</button>
    </form>

    <div class="ke-board-form-success" data-ke-board-success hidden>
        <span class="ke-board-form-success__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 4.5-5"/></svg>
        </span>
        <h3>¡Actividad enviada!</h3>
        <p>Está pendiente de revisión y aparecerá en el board cuando sea aprobada.</p>
    </div>

<?php endif; ?>
</div>
