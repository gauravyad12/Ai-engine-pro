module.exports = {
  "env": {
    "node": true
  },
  "extends": [
    "eslint:recommended",
    "plugin:react/recommended",
    "plugin:react-hooks/recommended"
  ],
  "parserOptions": {
    "ecmaVersion": 'latest',
    "sourceType": "module",
    "ecmaFeatures": {
      "jsx": true
    }
  },
  "globals": {
    "window": true,
    "document": true,
    "navigator": true,
    "history": true,
    "alert": true,
    "confirm": true,
    "prompt": true,
    "localStorage": true,
    "Promise": true,
    "FileReader": true,
    "MutationObserver": true,
    "wp": true,
    "mgl_map": true,
    "mgl_settings": true,
    "mwl": true,
    "Event": true,
    "google": true,
  },
  "rules": {
    "no-console": [1, { allow: ["warn", "error"] }],
    "prefer-const": 2,
    "no-var": 2,
    "indent": ["error", 2],
    "semi": ["error", "always"],
    "react/react-in-jsx-scope": "off",
    "react/jsx-uses-react": "off",
    "react/prop-types": "off"
  },
  "settings": {
    "react": {
      "version": "detect"
    }
  },
  "plugins": [
    "@babel/plugin-proposal-optional-chaining"
  ]
};
