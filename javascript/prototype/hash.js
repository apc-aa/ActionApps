var Hash = {
  _each: function(iterator) {
    for (var key in this) {
      var value = this[key];
      if (typeof value == 'function') continue;
      
      var pair = [key, value];
      pair.key = key;
      pair.value = value;
      iterator(pair);
    }
  },
  
  keys: function() {
    return this.pluck('key');
  },
  
  values: function() {
    return this.pluck('value');
  },
  
  merge: function(hash) {
    return $H(hash).inject(this, function(mergedHash, pair) {
      mergedHash[pair.key] = pair.value;
      return mergedHash;
    });
  },
  
  toQueryString: function() {
    return this.map(function(pair) {
      if (!pair.key) return null;
      
      if (pair.value && pair.value.constructor == Array) {
        pair.value = pair.value.compact();
        
        if (pair.value.length < 2) {
          pair.value = pair.value.reduce();
        } else {
          var key = encodeURIComponent(pair.key);
          return pair.value.map(function(value) {
            return key + '=' + encodeURIComponent(value);
		  	  }).join('&');
        }
      }
      
      if (pair.value == undefined) pair[1] = '';
      return pair.map(encodeURIComponent).join('=');
    }).join('&');
  },
  
  inspect: function() {
    return '#<Hash:{' + this.map(function(pair) {
      return pair.map(Object.inspect).join(': ');
    }).join(', ') + '}>';
  }
}

function $H(object) {
  var hash = Object.extend({}, object || {});
  Object.extend(hash, Enumerable);
  Object.extend(hash, Hash);
  return hash;
}
