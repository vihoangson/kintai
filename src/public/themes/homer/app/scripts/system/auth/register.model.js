(function() {
/**
 * @author ntd1712
 */
chaos.service("RegisterModel", Anonymous);

function Anonymous(AbstractModel) {
    function RegisterModel(data) {
        RegisterModel.parent.constructor.apply(this, [data, RegisterModel.getFields()]);
    }
    extend(RegisterModel, AbstractModel);

    /**
     * @returns {String}
     */
    RegisterModel.getRoute = function() {
        return "auth/register";
    };

    /**
     * @return {Object[]}
     */
    RegisterModel.getFields = function() {
        return [{
            data: "Id",
            value: 0,
            visible: false
        },{
            data: "Name",
            value: "",
            title: "Username"
        },{
            data: "Email",
            value: "",
            title: "Email"
        },{
            data: "Password",
            value: "",
            visible: false
        },{
            data: "ConfirmPassword",
            value: "",
            visible: false
        },{
            data: "Profile",
            value: "",
            visible: false
        },{
            data: "Roles",
            value: [],
            visible: false
        }];
    };

    return RegisterModel;
}

})();