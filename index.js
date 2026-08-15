/**
 * Panel: structure table shows photo URL fields as thumbnails.
 * Editor stays a normal URL input (preview components are table-only).
 */
panel.plugin("diplix/blockroll", {
  components: {
    "k-url-field-preview": {
      props: {
        value: [String, Number],
        column: Object,
        field: Object,
      },
      computed: {
        raw() {
          return (this.value || "").toString().trim();
        },
        isAvatar() {
          return this.field?.name === "photo";
        },
        src() {
          if (!this.raw) {
            return "";
          }
          return "/blockroll/image?url=" + encodeURIComponent(this.raw);
        },
      },
      template: `
        <div class="k-blockroll-photo-field-preview" v-if="isAvatar">
          <img
            v-if="src"
            :src="src"
            alt=""
            loading="lazy"
            @error="$event.target.style.visibility='hidden'"
          />
          <span v-else class="k-blockroll-photo-field-preview__empty" aria-hidden="true"></span>
        </div>
        <div v-else>
          <a
            v-if="raw"
            :href="raw"
            class="k-link"
            target="_blank"
            rel="noopener noreferrer"
          >{{ raw }}</a>
        </div>
      `,
    },
  },
});
