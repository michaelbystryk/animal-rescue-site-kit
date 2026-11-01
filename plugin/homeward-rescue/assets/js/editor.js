/* No build step: WordPress provides all editor dependencies. */
(function (wp) {
  'use strict';
  var el = wp.element.createElement;
  /*
   * Block names only. Attribute schemas, titles and icons live in each block.json, which WordPress
   * merges into the registered block type, so they are read back from there rather than repeated here.
   */
  var slugs = [
    'contact-form',
    'story-tabs',
    'donate-panel',
    'upcoming-events',
    'apply-button',
    'animal-grid',
    'animal-profile',
    'animal-carousel',
    'story-cards',
    'story-feature',
  ];
  var labels = {
    topic: wp.i18n.__('Topic', 'homeward-rescue'),
    intro: wp.i18n.__('Introduction', 'homeward-rescue'),
    button: wp.i18n.__('Button label', 'homeward-rescue'),
    privacy: wp.i18n.__('Privacy note', 'homeward-rescue'),
    animal: wp.i18n.__('Animal post ID (optional)', 'homeward-rescue'),
    breedFilter: wp.i18n.__('Breed family filter', 'homeward-rescue'),
    apply: wp.i18n.__('Apply', 'homeward-rescue'),
    columns: wp.i18n.__('Columns', 'homeward-rescue'),
    compatibility: wp.i18n.__('Compatibility', 'homeward-rescue'),
    count: wp.i18n.__('Count', 'homeward-rescue'),
    courtesySection: wp.i18n.__('Courtesy section', 'homeward-rescue'),
    description: wp.i18n.__('Description', 'homeward-rescue'),
    facts: wp.i18n.__('Facts', 'homeward-rescue'),
    featured: wp.i18n.__('Featured', 'homeward-rescue'),
    filters: wp.i18n.__('Filters', 'homeward-rescue'),
    gallery: wp.i18n.__('Gallery', 'homeward-rescue'),
    heading: wp.i18n.__('Heading', 'homeward-rescue'),
    kind: wp.i18n.__('Kind', 'homeward-rescue'),
    layout: wp.i18n.__('Layout', 'homeward-rescue'),
    limit: wp.i18n.__('Limit', 'homeward-rescue'),
    methods: wp.i18n.__('Methods', 'homeward-rescue'),
    moreFromRegion: wp.i18n.__('More from region', 'homeward-rescue'),
    region: wp.i18n.__('Region', 'homeward-rescue'),
    showPast: wp.i18n.__('Show past', 'homeward-rescue'),
    source: wp.i18n.__('Source', 'homeward-rescue'),
    species: wp.i18n.__('Species', 'homeward-rescue'),
    status: wp.i18n.__('Status', 'homeward-rescue'),
    type: wp.i18n.__('Type', 'homeward-rescue'),
    video: wp.i18n.__('Video', 'homeward-rescue'),
  };
  slugs.forEach(function (slug) {
    var name = 'homeward/' + slug;
    wp.blocks.registerBlockType(name, {
      edit: function (props) {
        var definition = wp.blocks.getBlockType(name).attributes;
        var controls = Object.keys(definition)
          .filter(function (key) {
            return labels[key];
          })
          .map(function (key) {
            var field = definition[key],
              value = props.attributes[key];
            var options = {
              key: key,
              label: labels[key],
              onChange: function (v) {
                var update = {};
                update[key] =
                  field.type === 'number'
                    ? Math.max(0, parseInt(v, 10) || 0)
                    : field.type === 'array'
                      ? v
                          .split(',')
                          .map(function (s) {
                            return s.trim();
                          })
                          .filter(Boolean)
                      : v;
                props.setAttributes(update);
              },
            };
            if (field.type === 'boolean') {
              options.checked = !!value;
              return el(wp.components.ToggleControl, options);
            }
            if (field.enum) {
              options.value = value;
              options.options = field.enum.map(function (v) {
                return { label: v || wp.i18n.__('Any', 'homeward-rescue'), value: v };
              });
              return el(wp.components.SelectControl, options);
            }
            if (field.type === 'number' && key !== 'animal') {
              options.value = value;
              options.min = key === 'limit' ? 0 : 1;
              options.max = key === 'columns' ? 6 : 100;
              return el(wp.components.RangeControl, options);
            }
            if (key === 'animal') {
              options.type = 'number';
              options.min = 0;
            }
            options.value = Array.isArray(value) ? value.join(',') : value;
            return el(wp.components.TextControl, options);
          });
        var preview = wp.serverSideRender.default || wp.serverSideRender;
        return el(
          'div',
          wp.blockEditor.useBlockProps(),
          el(
            wp.blockEditor.InspectorControls,
            null,
            el(wp.components.PanelBody, { title: wp.i18n.__('Homeward', 'homeward-rescue') }, controls)
          ),
          el(preview, { block: name, attributes: props.attributes })
        );
      },
      save: function () {
        return null;
      },
    });
  });
})(window.wp);
