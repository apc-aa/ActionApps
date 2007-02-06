var Form = {
  reset: function(form) {
    $(form).reset();
    return form;
  },
  
  serializeElements: function(elements) {
    return elements.inject([], function(queryComponents, element) {
      var queryComponent = Form.Element.serialize(element);
      if (queryComponent) queryComponents.push(queryComponent);
      return queryComponents;
    }).join('&');
  }
};

Form.Methods = {
  serialize: function(form) {
    return Form.serializeElements($(form).getElements());
  },
  
  getElements: function(form) {
    return $A($(form).getElementsByTagName('*')).inject([], 
      function(elements, child) {
        if (Form.Element.Serializers[child.tagName.toLowerCase()])
          elements.push(Element.extend(child));
        return elements;
      }
    );
  },
  
  getInputs: function(form, typeName, name) {
    form = $(form);
    var inputs = form.getElementsByTagName('input');
    
    if (!typeName && !name)
      return inputs;
      
    var matchingInputs = new Array();
    for (var i = 0, length = inputs.length; i < length; i++) {
      var input = inputs[i];
      if ((typeName && input.type != typeName) ||
          (name && input.name != name)) 
        continue;
      matchingInputs.push(Element.extend(input));
    }

    return matchingInputs;
  },

  disable: function(form) {
    form = $(form);
    form.getElements().each(function(element) {
      element.blur();
      element.disabled = 'true';
    });
    return form;
  },

  enable: function(form) {
    form = $(form);
    form.getElements().each(function(element) {
      element.disabled = '';
    });
    return form;
  },

  findFirstElement: function(form) {
    return $(form).getElements().find(function(element) {
      return element.type != 'hidden' && !element.disabled &&
        ['input', 'select', 'textarea'].include(element.tagName.toLowerCase());
    });
  },

  focusFirstElement: function(form) {
    form = $(form);
    form.findFirstElement().activate();
    return form;
  }
}

Object.extend(Form, Form.Methods);

/*--------------------------------------------------------------------------*/

Form.Element = {
  focus: function(element) {
    $(element).focus();
    return element;
  },

  select: function(element) {
    $(element).select();
    return element;
  }
}

Form.Element.Methods = {
  serialize: function(element) {
    element = $(element);
    if (element.disabled) return '';
    var method = element.tagName.toLowerCase();
    var parameter = Form.Element.Serializers[method](element);
    
    if (parameter) {
      var key = encodeURIComponent(parameter[0]);
      if (key.length == 0) return;
      
      if (parameter[1].constructor != Array)
        parameter[1] = [parameter[1]];
      
      return parameter[1].map(function(value) {
        return key + '=' + encodeURIComponent(value);
      }).join('&');
    }
  },
  
  getValue: function(element) {
    element = $(element);
    var method = element.tagName.toLowerCase();
    var parameter = Form.Element.Serializers[method](element);
    
    if (parameter)
      return parameter[1];
  },

  clear: function(element) {
    $(element).value = '';
    return element;
  },

  present: function(element) {
    return $(element).value != '';
  },
  
  activate: function(element) {
    element = $(element);
    element.focus();
    if (element.select && ( element.tagName.toLowerCase() != 'input' ||
      !['button', 'reset', 'submit'].include(element.type) ) )
      element.select();
    return element;
  },
  
  disable: function(element) {
    element = $(element);
    element.disabled = true;
    return element;
  },
  
  enable: function(element) {
    element = $(element);
    element.blur();
    element.disabled = false;
    return element;
  }
}

Object.extend(Form.Element, Form.Element.Methods);
var Field = Form.Element;

/*--------------------------------------------------------------------------*/

Form.Element.Serializers = {
  input: function(element) {
    switch (element.type.toLowerCase()) {
      case 'checkbox':  
      case 'radio':
        return Form.Element.Serializers.inputSelector(element);
      default:
        return Form.Element.Serializers.textarea(element);
    }
    return false;
  },

  inputSelector: function(element) {
    if (element.checked)
      return [element.name, element.value];
  },

  textarea: function(element) {
    return [element.name, element.value];
  },
  
  select: function(element) {
    return Form.Element.Serializers[element.type == 'select-one' ? 
      'selectOne' : 'selectMany'](element);
  },
  
  selectOne: function(element) {
    var value = '', opt, index = element.selectedIndex;
    if (index >= 0) {
      opt = Element.extend(element.options[index]);
      // Uses the new potential extension if hasAttribute isn't native.
      value = opt.hasAttribute('value') ? opt.value : opt.text;
    }
    return [element.name, value];
  },
  
  selectMany: function(element) {
    var value = [];
    for (var i = 0, length = element.length; i < length; i++) {
      var opt = Element.extend(element.options[i]);
      if (opt.selected)
        // Uses the new potential extension if hasAttribute isn't native.
        value.push(opt.hasAttribute('value') ? opt.value : opt.text);
    }
    return [element.name, value];
  }
}

/*--------------------------------------------------------------------------*/

var $F = Form.Element.getValue;

/*--------------------------------------------------------------------------*/

Abstract.TimedObserver = function() {}
Abstract.TimedObserver.prototype = {
  initialize: function(element, frequency, callback) {
    this.frequency = frequency;
    this.element   = $(element);
    this.callback  = callback;
    
    this.lastValue = this.getValue();
    this.registerCallback();
  },
  
  registerCallback: function() {
    setInterval(this.onTimerEvent.bind(this), this.frequency * 1000);
  },
  
  onTimerEvent: function() {
    var value = this.getValue();
    if (this.lastValue != value) {
      this.callback(this.element, value);
      this.lastValue = value;
    }
  }
}

Form.Element.Observer = Class.create();
Form.Element.Observer.prototype = Object.extend(new Abstract.TimedObserver(), {
  getValue: function() {
    return Form.Element.getValue(this.element);
  }
});

Form.Observer = Class.create();
Form.Observer.prototype = Object.extend(new Abstract.TimedObserver(), {
  getValue: function() {
    return Form.serialize(this.element);
  }
});

/*--------------------------------------------------------------------------*/

Abstract.EventObserver = function() {}
Abstract.EventObserver.prototype = {
  initialize: function(element, callback) {
    this.element  = $(element);
    this.callback = callback;
    
    this.lastValue = this.getValue();
    if (this.element.tagName.toLowerCase() == 'form')
      this.registerFormCallbacks();
    else
      this.registerCallback(this.element);
  },
  
  onElementEvent: function() {
    var value = this.getValue();
    if (this.lastValue != value) {
      this.callback(this.element, value);
      this.lastValue = value;
    }
  },
  
  registerFormCallbacks: function() {
    Form.getElements(this.element).each(this.registerCallback.bind(this));
  },
  
  registerCallback: function(element) {
    if (element.type) {
      switch (element.type.toLowerCase()) {
        case 'checkbox':  
        case 'radio':
          Event.observe(element, 'click', this.onElementEvent.bind(this));
          break;
        default:
          Event.observe(element, 'change', this.onElementEvent.bind(this));
          break;
      }
    }    
  }
}

Form.Element.EventObserver = Class.create();
Form.Element.EventObserver.prototype = Object.extend(new Abstract.EventObserver(), {
  getValue: function() {
    return Form.Element.getValue(this.element);
  }
});

Form.EventObserver = Class.create();
Form.EventObserver.prototype = Object.extend(new Abstract.EventObserver(), {
  getValue: function() {
    return Form.serialize(this.element);
  }
});

