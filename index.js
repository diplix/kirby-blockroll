/**
 * Panel: avatar thumbnails (custom field preview) + Discover on URL field.
 * Do not replace core k-url-field-preview — that breaks System → Plugins links.
 */
panel.plugin("diplix/blockroll", {
  components: {
    // Preview for type: blockroll-photo only (see blueprints/blocks/blogroll.yml)
    "k-blockroll-photo-field-preview": {
      props: {
        value: [String, Number],
        column: Object,
        field: Object,
      },
      computed: {
        raw() {
          return (this.value || "").toString().trim();
        },
        src() {
          if (!this.raw) {
            return "";
          }
          const field = this.field || {};
          if (field.proxyPhotos !== true) {
            return this.raw;
          }
          const prefix = String(field.routePrefix || "blockroll").replace(
            /^\/+|\/+$/g,
            ""
          );
          const site = (this.$panel?.urls?.site || "").replace(/\/+$/, "");
          return (
            site +
            "/" +
            prefix +
            "/image?url=" +
            encodeURIComponent(this.raw)
          );
        },
      },
      template: `
        <div class="k-blockroll-photo-field-preview">
          <img
            v-if="src"
            :src="src"
            alt=""
            loading="lazy"
            @error="$event.target.style.visibility='hidden'"
          />
          <span v-else class="k-blockroll-photo-field-preview__empty" aria-hidden="true"></span>
        </div>
      `,
    },
  },
  fields: {
    // Same as url in the drawer; structure table uses k-blockroll-photo-field-preview
    "blockroll-photo": {
      extends: "k-url-field",
    },
    "blockroll-url": {
      extends: "k-url-field",
      data() {
        return {
          busy: false,
        };
      },
      methods: {
        findFieldset() {
          let parent = this.$parent;
          while (parent) {
            const value = parent.value;
            if (
              value &&
              typeof value === "object" &&
              !Array.isArray(value) &&
              Object.prototype.hasOwnProperty.call(value, "url") &&
              parent.fields &&
              typeof parent.fields === "object"
            ) {
              return parent;
            }
            parent = parent.$parent;
          }
          return null;
        },
        resolveFieldKey(fieldset, name) {
          const value = fieldset.value || {};
          const fields = fieldset.fields || {};
          if (Object.prototype.hasOwnProperty.call(value, name)) {
            return name;
          }
          if (Object.prototype.hasOwnProperty.call(fields, name)) {
            return name;
          }
          const lower = name.toLowerCase();
          const fromValue = Object.keys(value).find(
            (key) => key.toLowerCase() === lower
          );
          if (fromValue) {
            return fromValue;
          }
          const fromFields = Object.keys(fields).find(
            (key) => key.toLowerCase() === lower
          );
          return fromFields || lower;
        },
        applyDiscovered(data) {
          const fieldset = this.findFieldset();
          if (!fieldset) {
            this.$panel.notification.error(
              "Discover: Formular nicht gefunden — Felder bitte manuell prüfen."
            );
            return 0;
          }

          const next = { ...fieldset.value };
          const keys = ["name", "description", "feedUrl", "photo"];
          let filled = 0;

          for (const key of keys) {
            const incoming = (data?.[key] || "").toString().trim();
            if (!incoming) {
              continue;
            }
            const target = this.resolveFieldKey(fieldset, key);
            const current = (next[target] || "").toString().trim();
            if (current === "") {
              next[target] = incoming;
              filled++;
            }
          }

          if (filled > 0) {
            fieldset.$emit("input", next);
          }

          return filled;
        },
        async discover() {
          const url = (this.value || "").toString().trim();
          if (!url || this.busy) {
            return;
          }

          this.busy = true;
          try {
            const response = await this.$api.post("blockroll/discover", { url });
            const data = response?.data || {};
            const apiError =
              response?.status === "error"
                ? response?.message || "Discover fehlgeschlagen"
                : null;
            const filled = this.applyDiscovered(data);
            const empty =
              !data.name &&
              !data.description &&
              !data.feedUrl &&
              !data.photo;

            if (filled > 0) {
              this.$panel.notification.success(
                filled === 1
                  ? "1 Feld befüllt"
                  : filled + " Felder befüllt"
              );
            } else if (apiError) {
              this.$panel.notification.error(apiError);
            } else if (empty) {
              this.$panel.notification.error(
                "Keine Metadaten gefunden — URL prüfen oder Felder manuell füllen."
              );
            } else {
              this.$panel.notification.info(
                "Alle Felder waren schon befüllt — nichts geändert."
              );
            }
          } catch (error) {
            const message =
              error?.message ||
              error?.response?.message ||
              "Discover fehlgeschlagen";
            this.$panel.notification.error(message);
          } finally {
            this.busy = false;
          }
        },
      },
      template: `
        <k-field
          v-bind="$props"
          :input="id"
          class="k-url-field k-blockroll-url-field"
        >
          <div class="k-blockroll-url-field__row">
            <k-input
              :id="id"
              ref="input"
              type="url"
              theme="field"
              :value="value"
              :required="required"
              :disabled="disabled"
              :placeholder="placeholder"
              @input="$emit('input', $event)"
            />
            <k-button
              class="k-blockroll-url-field__discover"
              type="button"
              variant="filled"
              size="sm"
              icon="search"
              :disabled="busy || !(value || '').toString().trim()"
              :text="busy ? '…' : 'Discover'"
              @click="discover"
            />
          </div>
        </k-field>
      `,
    },
  },
});
