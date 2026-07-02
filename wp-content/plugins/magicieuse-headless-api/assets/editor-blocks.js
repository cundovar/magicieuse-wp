(function (wp) {
  const { registerBlockType } = wp.blocks
  const { InspectorControls, MediaUpload, MediaUploadCheck, RichText } = wp.blockEditor
  const { Button, PanelBody, TextControl, SelectControl, TextareaControl } = wp.components
  const { createElement: el, Fragment } = wp.element
  const { __ } = wp.i18n

  const imageButtonText = (imageId) =>
    imageId ? __('Changer l’image', 'magicieuse') : __('Choisir une image', 'magicieuse')

  function MediaControl({ attributes, setAttributes }) {
    return el(
      MediaUploadCheck,
      null,
      el(MediaUpload, {
        allowedTypes: ['image'],
        value: attributes.imageId,
        onSelect: (media) => setAttributes({ imageId: media.id, imageUrl: media.url }),
        render: ({ open }) =>
          el(
            Button,
            {
              variant: attributes.imageId ? 'secondary' : 'primary',
              onClick: open,
            },
            imageButtonText(attributes.imageId),
          ),
      }),
    )
  }

  function ButtonFields({ attributes, setAttributes, hero }) {
    return el(
      PanelBody,
      { title: __('Bouton', 'magicieuse'), initialOpen: true },
      el(TextControl, {
        label: __('Libellé', 'magicieuse'),
        value: hero ? attributes.primaryButtonLabel : attributes.buttonLabel,
        onChange: (value) =>
          setAttributes(hero ? { primaryButtonLabel: value } : { buttonLabel: value }),
      }),
      el(TextControl, {
        label: __('URL', 'magicieuse'),
        value: hero ? attributes.primaryButtonUrl : attributes.buttonUrl,
        onChange: (value) =>
          setAttributes(hero ? { primaryButtonUrl: value } : { buttonUrl: value }),
      }),
      hero &&
        el(TextControl, {
          label: __('Libellé secondaire', 'magicieuse'),
          value: attributes.secondaryButtonLabel,
          onChange: (value) => setAttributes({ secondaryButtonLabel: value }),
        }),
      hero &&
        el(TextControl, {
          label: __('URL secondaire', 'magicieuse'),
          value: attributes.secondaryButtonUrl,
          onChange: (value) => setAttributes({ secondaryButtonUrl: value }),
        }),
    )
  }

  function parseGridItems(value) {
    return String(value || '')
      .split('\n')
      .map((row) => row.trim())
      .filter(Boolean)
      .map((row) => {
        const parts = row.split('|').map((part) => part.trim())
        return {
          title: parts[0] || '',
          text: parts[1] || '',
          url: parts[2] || '',
          linkLabel: parts[3] || '',
          columnSpan: parseInt(parts[4], 10) || 1,
          rowSpan: parseInt(parts[5], 10) || 1,
        }
      })
  }

  function serializeGridItems(items) {
    return items
      .map((item) =>
        [
          item.title,
          item.text,
          item.url,
          item.linkLabel,
          Math.max(1, Math.min(6, parseInt(item.columnSpan, 10) || 1)),
          Math.max(1, Math.min(4, parseInt(item.rowSpan, 10) || 1)),
        ].join(' | '),
      )
      .join('\n')
  }

  function CssGridItemsEditor({ attributes, setAttributes }) {
    const items = parseGridItems(attributes.items)
    const updateItems = (nextItems) => setAttributes({ items: serializeGridItems(nextItems) })
    const updateItem = (index, patch) => {
      updateItems(items.map((item, itemIndex) => (itemIndex === index ? { ...item, ...patch } : item)))
    }
    const moveItem = (index, direction) => {
      const target = index + direction
      if (target < 0 || target >= items.length) return
      const nextItems = items.slice()
      const current = nextItems[index]
      nextItems[index] = nextItems[target]
      nextItems[target] = current
      updateItems(nextItems)
    }
    const removeItem = (index) => updateItems(items.filter((_, itemIndex) => itemIndex !== index))
    const addItem = () => updateItems([...items, { title: '', text: '', url: '', linkLabel: '', columnSpan: 1, rowSpan: 1 }])
    const columnCount = Math.max(1, Math.min(6, attributes.columns || 3))
    const gridTemplateColumns = `repeat(${columnCount}, minmax(0, 1fr))`

    return el(
      'div',
      null,
      el(
        'div',
        {
          className: 'magicieuse-editor-css-grid-preview',
          style: {
            display: 'grid',
            gridTemplateColumns,
            gap: `${Math.max(0, attributes.gap || 24)}px`,
            marginTop: '18px',
          },
        },
        items.length
          ? items.map((item, index) =>
              el(
                'article',
                {
                  key: index,
                  className: 'magicieuse-editor-css-grid-card',
                  style: {
                    position: 'relative',
                    display: 'grid',
                    gridColumn: `span ${Math.max(1, Math.min(columnCount, parseInt(item.columnSpan, 10) || 1))}`,
                    gridRow: `span ${Math.max(1, Math.min(4, parseInt(item.rowSpan, 10) || 1))}`,
                    gap: '10px',
                    minHeight: '150px',
                    padding: '16px',
                    border: '1px solid #d8c9e8',
                    borderRadius: '12px',
                    background: '#fff',
                    boxShadow: '0 8px 22px rgba(46,20,64,.08)',
                  },
                },
                el(
                  'div',
                  {
                    style: {
                      display: 'flex',
                      gap: '6px',
                      justifyContent: 'flex-end',
                      marginBottom: '4px',
                    },
                  },
                  el(
                    Button,
                    {
                      size: 'small',
                      variant: 'secondary',
                      disabled: index === 0,
                      onClick: () => moveItem(index, -1),
                    },
                    '↑',
                  ),
                  el(
                    Button,
                    {
                      size: 'small',
                      variant: 'secondary',
                      disabled: index === items.length - 1,
                      onClick: () => moveItem(index, 1),
                    },
                    '↓',
                  ),
                  el(
                    Button,
                    {
                      size: 'small',
                      variant: 'tertiary',
                      isDestructive: true,
                      onClick: () => removeItem(index),
                    },
                    __('Supprimer', 'magicieuse'),
                  ),
                ),
                el(TextControl, {
                  label: __('Titre', 'magicieuse'),
                  value: item.title,
                  onChange: (value) => updateItem(index, { title: value }),
                }),
                el(TextareaControl, {
                  label: __('Texte', 'magicieuse'),
                  value: item.text,
                  rows: 3,
                  onChange: (value) => updateItem(index, { text: value }),
                }),
                el(TextControl, {
                  label: __('URL', 'magicieuse'),
                  value: item.url,
                  onChange: (value) => updateItem(index, { url: value }),
                }),
                el(TextControl, {
                  label: __('Libellé du lien', 'magicieuse'),
                  value: item.linkLabel,
                  onChange: (value) => updateItem(index, { linkLabel: value }),
                }),
                el(
                  'div',
                  {
                    style: {
                      display: 'grid',
                      gridTemplateColumns: '1fr 1fr',
                      gap: '10px',
                    },
                  },
                  el(TextControl, {
                    label: __('Largeur (colonnes)', 'magicieuse'),
                    type: 'number',
                    min: 1,
                    max: columnCount,
                    value: item.columnSpan || 1,
                    onChange: (value) =>
                      updateItem(index, {
                        columnSpan: Math.max(1, Math.min(columnCount, parseInt(value, 10) || 1)),
                      }),
                  }),
                  el(TextControl, {
                    label: __('Hauteur (lignes)', 'magicieuse'),
                    type: 'number',
                    min: 1,
                    max: 4,
                    value: item.rowSpan || 1,
                    onChange: (value) =>
                      updateItem(index, {
                        rowSpan: Math.max(1, Math.min(4, parseInt(value, 10) || 1)),
                      }),
                  }),
                ),
              ),
            )
          : el(
              'p',
              {
                style: {
                  margin: 0,
                  padding: '18px',
                  border: '1px dashed #b9a8cc',
                  borderRadius: '12px',
                  color: '#6b5a7e',
                },
              },
              __('Aucun item pour le moment.', 'magicieuse'),
            ),
      ),
      el(
        Button,
        {
          variant: 'primary',
          onClick: addItem,
          style: { marginTop: '14px' },
        },
        __('Ajouter une carte', 'magicieuse'),
      ),
    )
  }

  registerBlockType('magicieuse/hero', {
    title: 'Hero Magicieuse',
    icon: 'cover-image',
    category: 'magicieuse',
    attributes: {
      title: { type: 'string', default: '' },
      subtitle: { type: 'string', default: '' },
      text: { type: 'string', default: '' },
      imageId: { type: 'number' },
      imageUrl: { type: 'string', default: '' },
      primaryButtonLabel: { type: 'string', default: '' },
      primaryButtonUrl: { type: 'string', default: '' },
      secondaryButtonLabel: { type: 'string', default: '' },
      secondaryButtonUrl: { type: 'string', default: '' },
    },
    edit: ({ attributes, setAttributes }) =>
      el(
        Fragment,
        null,
        el(
          InspectorControls,
          null,
          el(
            PanelBody,
            { title: __('Image', 'magicieuse'), initialOpen: true },
            el(MediaControl, { attributes, setAttributes }),
          ),
          el(ButtonFields, { attributes, setAttributes, hero: true }),
        ),
        el(
          'section',
          { className: 'magicieuse-editor-block magicieuse-editor-block--hero' },
          el(RichText, {
            tagName: 'p',
            placeholder: __('Sous-titre', 'magicieuse'),
            value: attributes.subtitle,
            onChange: (value) => setAttributes({ subtitle: value }),
          }),
          el(RichText, {
            tagName: 'h2',
            placeholder: __('Titre du hero', 'magicieuse'),
            value: attributes.title,
            onChange: (value) => setAttributes({ title: value }),
          }),
          el(RichText, {
            tagName: 'p',
            placeholder: __('Texte', 'magicieuse'),
            value: attributes.text,
            onChange: (value) => setAttributes({ text: value }),
          }),
          attributes.primaryButtonLabel &&
            el('span', { className: 'button button-primary' }, attributes.primaryButtonLabel),
          attributes.imageUrl &&
            el('img', {
              src: attributes.imageUrl,
              alt: '',
              style: { maxWidth: '100%', height: 'auto', marginTop: '16px' },
            }),
        ),
      ),
    save: () => null,
  })

  registerBlockType('magicieuse/image-text', {
    title: 'Image + texte Magicieuse',
    icon: 'align-pull-left',
    category: 'magicieuse',
    attributes: {
      title: { type: 'string', default: '' },
      text: { type: 'string', default: '' },
      imageId: { type: 'number' },
      imageUrl: { type: 'string', default: '' },
      imagePosition: { type: 'string', default: 'left' },
      buttonLabel: { type: 'string', default: '' },
      buttonUrl: { type: 'string', default: '' },
    },
    edit: ({ attributes, setAttributes }) =>
      el(
        Fragment,
        null,
        el(
          InspectorControls,
          null,
          el(
            PanelBody,
            { title: __('Image', 'magicieuse'), initialOpen: true },
            el(MediaControl, { attributes, setAttributes }),
            el(SelectControl, {
              label: __('Position de l’image', 'magicieuse'),
              value: attributes.imagePosition,
              options: [
                { label: __('Gauche', 'magicieuse'), value: 'left' },
                { label: __('Droite', 'magicieuse'), value: 'right' },
              ],
              onChange: (value) => setAttributes({ imagePosition: value }),
            }),
          ),
          el(ButtonFields, { attributes, setAttributes, hero: false }),
        ),
        el(
          'section',
          { className: 'magicieuse-editor-block magicieuse-editor-block--image-text' },
          attributes.imageUrl &&
            el('img', {
              src: attributes.imageUrl,
              alt: '',
              style: { maxWidth: '100%', height: 'auto', marginBottom: '16px' },
            }),
          el(RichText, {
            tagName: 'h2',
            placeholder: __('Titre', 'magicieuse'),
            value: attributes.title,
            onChange: (value) => setAttributes({ title: value }),
          }),
          el(RichText, {
            tagName: 'p',
            placeholder: __('Texte', 'magicieuse'),
            value: attributes.text,
            onChange: (value) => setAttributes({ text: value }),
          }),
          attributes.buttonLabel &&
            el('span', { className: 'button button-primary' }, attributes.buttonLabel),
        ),
      ),
    save: () => null,
  })

  registerBlockType('magicieuse/instagram-feed', {
    title: 'Instagram Magicieuse',
    icon: 'instagram',
    category: 'magicieuse',
    attributes: {
      title: { type: 'string', default: 'Instagram' },
      limit: { type: 'number', default: 8 },
      feedId: { type: 'string', default: '' },
    },
    edit: ({ attributes, setAttributes }) =>
      el(
        Fragment,
        null,
        el(
          InspectorControls,
          null,
          el(
            PanelBody,
            { title: __('Réglages Instagram', 'magicieuse'), initialOpen: true },
            el(TextControl, {
              label: __('Nombre de publications', 'magicieuse'),
              type: 'number',
              min: 1,
              max: 24,
              value: attributes.limit,
              onChange: (value) => setAttributes({ limit: parseInt(value, 10) || 8 }),
            }),
            el(TextControl, {
              label: __('Feed ID Smash Balloon optionnel', 'magicieuse'),
              value: attributes.feedId,
              onChange: (value) => setAttributes({ feedId: value }),
            }),
          ),
        ),
        el(
          'section',
          { className: 'magicieuse-editor-block magicieuse-editor-block--instagram' },
          el(RichText, {
            tagName: 'h2',
            placeholder: __('Titre', 'magicieuse'),
            value: attributes.title,
            onChange: (value) => setAttributes({ title: value }),
          }),
          el(
            'p',
            null,
            __('Le front React affichera les publications Instagram mises en cache par Smash Balloon.', 'magicieuse'),
          ),
        ),
      ),
    save: () => null,
  })

  registerBlockType('magicieuse/css-grid', {
    title: 'CSS Grid Magicieuse',
    icon: 'grid-view',
    category: 'magicieuse',
    attributes: {
      title: { type: 'string', default: '' },
      text: { type: 'string', default: '' },
      columns: { type: 'number', default: 3 },
      minColumnWidth: { type: 'number', default: 220 },
      gap: { type: 'number', default: 24 },
      variant: { type: 'string', default: 'cards' },
      items: { type: 'string', default: '' },
    },
    edit: ({ attributes, setAttributes }) =>
      el(
        Fragment,
        null,
        el(
          InspectorControls,
          null,
          el(
            PanelBody,
            { title: __('Réglages de grille', 'magicieuse'), initialOpen: true },
            el(TextControl, {
              label: __('Colonnes maximum', 'magicieuse'),
              type: 'number',
              min: 1,
              max: 6,
              value: attributes.columns,
              onChange: (value) => setAttributes({ columns: parseInt(value, 10) || 3 }),
            }),
            el(TextControl, {
              label: __('Largeur minimum d’une colonne', 'magicieuse'),
              type: 'number',
              min: 120,
              max: 520,
              value: attributes.minColumnWidth,
              onChange: (value) => setAttributes({ minColumnWidth: parseInt(value, 10) || 220 }),
            }),
            el(TextControl, {
              label: __('Espacement', 'magicieuse'),
              type: 'number',
              min: 0,
              max: 80,
              value: attributes.gap,
              onChange: (value) => setAttributes({ gap: parseInt(value, 10) || 24 }),
            }),
            el(SelectControl, {
              label: __('Style', 'magicieuse'),
              value: attributes.variant,
              options: [
                { label: __('Cartes', 'magicieuse'), value: 'cards' },
                { label: __('Simple', 'magicieuse'), value: 'plain' },
                { label: __('Mosaïque', 'magicieuse'), value: 'masonry' },
              ],
              onChange: (value) => setAttributes({ variant: value }),
            }),
          ),
        ),
        el(
          'section',
          { className: 'magicieuse-editor-block magicieuse-editor-block--css-grid' },
          el(RichText, {
            tagName: 'h2',
            placeholder: __('Titre', 'magicieuse'),
            value: attributes.title,
            onChange: (value) => setAttributes({ title: value }),
          }),
          el(RichText, {
            tagName: 'p',
            placeholder: __('Texte d’introduction', 'magicieuse'),
            value: attributes.text,
            onChange: (value) => setAttributes({ text: value }),
          }),
          el(CssGridItemsEditor, { attributes, setAttributes }),
        ),
      ),
    save: () => null,
  })

})(window.wp)
