const funcs = require('../functions/functions')
const compiledTemplates = require('../compiledTemplates/compiledTemplates')
const socketEvents = require('../events/events')
const { Sequelize, Op, DataTypes } = require("sequelize");
const striptags = require('striptags');
const moment = require("moment")

const DeclineCallController = async (ctx, data, io,socket) => {
  let user_id = ctx.userHashUserId[data.user_id];
  await io.to(user_id).emit('decline_call', {});
};

module.exports = { DeclineCallController };